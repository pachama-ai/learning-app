# Development environment

Notes about the **machine and the editor setup**, not about the application. Read
this when VS Code behaves strangely, not when the app does.

The application itself is covered by `docs/project-brief.md` and
`docs/verification.md`.

---

## 1. The setup

| | |
| --- | --- |
| Host | Windows desktop `DESKTOP-FP4OP26` (no battery, so no lid switch) |
| Guest | WSL2, Ubuntu, kernel `6.18.x`, `systemd=true`, no `.wslconfig` |
| Memory | 7.5 GiB inside the distro (WSL default: half of the host RAM) |
| Project | `/home/user/projects/learning-app` |
| Editor | VS Code with **Remote - WSL** (`ms-vscode-remote.remote-wsl`) |

From inside the distro the Windows drive is reachable as `/mnt/c`, so the VS Code
logs can be read without leaving the terminal:

```bash
# VS Code logs on the Windows side
/mnt/c/Users/User/AppData/Roaming/Code/logs/<timestamp>/window1/

# VS Code server logs inside WSL
~/.vscode-server/data/logs/<timestamp>/
```

---

## 2. "WSL: disconnected" / "Reload Window" (fixed 2026-09-26)

### Symptom

Out of nowhere the window shows **"WSL: Disconnected — Reload Window"**. Reloading
helps, but it comes back after a while. The application itself is fine: Apache/PHP
keep running, and the WSL distro never restarts.

### Cause

**The Windows standby idle timer.** When Windows goes to standby (S3), the WSL2 VM
is suspended. VS Code keeps the socket to the server open, so nothing happens for
minutes until the socket times out; only then does the window notice and ask for a
reload.

The active power plan was "Power Saver" (`SCHEME_MAX`) with

| Setting | Value before |
| --- | --- |
| Turn off hard disk / Standby after (AC) | 15 minutes |
| Standby after (DC) | 10 minutes |

On this day alone that produced **9 wake events**.

### Evidence

Three independent logs line up exactly. The standby period equals the time the VS
Code socket saw no data at all:

| Source | Entry |
| --- | --- |
| Windows event log | `Power-Troubleshooter` id 1: sleep **18:05:36** → wake **18:14:44** |
| `renderer.log` (Windows) | `socket timeout event (reason: unacknowledgedMessage ... timeSinceLastReceivedSomeData: 550690)` at 18:14:47 → 550 s earlier is **18:05:37** |
| `renderer.log` (Windows) | `18:14:44 Extension host (Remote) is unresponsive.` |

### Fix

```bash
powercfg.exe /change standby-timeout-ac 0      # never sleep, on mains
powercfg.exe /change standby-timeout-dc 0      # never sleep, on battery
powercfg.exe /change hibernate-timeout-ac 0    # never hibernate, on mains
```

Verify:

```bash
powercfg.exe /q SCHEME_CURRENT SUB_SLEEP
```

Expected: `STANDBYIDLE` (Deaktivierung nach) = `0x00000000` for **both** AC and DC.

Undo:

```bash
powercfg.exe /change standby-timeout-ac 15
powercfg.exe /change standby-timeout-dc 10
```

> The values belong to the **currently active power plan**. Switching plans in
> Windows brings the old timers back, so after such a switch the check above has to
> be repeated.
>
> A **manual** standby still drops the connection - that is expected. VS Code
> normally reconnects on its own after waking up; press "Reload Window" only if the
> window stays stuck.

### How to diagnose it again

Work from both ends inward, the Windows side first.

1. **Did the machine actually sleep?** This is the fastest test and it settles most
   cases:

   ```bash
   powershell.exe -NoProfile -Command "Get-WinEvent -FilterHashtable @{LogName='System'; ProviderName='Microsoft-Windows-Power-Troubleshooter'} -MaxEvents 10 | Select-Object TimeCreated,Id,Message | Format-List"
   ```

2. **What did the client see?** In `%APPDATA%\Code\logs\<timestamp>\window1\renderer.log`:

   ```bash
   grep -E "socket timeout event|resolveAuthority\(wsl\)|is unresponsive|reconnect" renderer.log
   ```

   - a large `timeSinceLastReceivedSomeData` means the socket was dead for that long
   - `resolveAuthority(wsl) returned ... after 4000+ ms` means `wsl.exe` on Windows
     was slow to answer (it normally returns in well under 100 ms)

3. **What did the server see?** In `~/.vscode-server/data/logs/<timestamp>/remoteagent.log`:

   - `The client has disconnected gracefully` → the window was closed or reloaded,
     this is normal
   - `The client has disconnected, will wait for reconnection 3h` → the client
     vanished abruptly, e.g. because the host slept

### Ruled out on this machine

Checked and found healthy, so they are not the explanation here. Worth re-checking
before blaming the WSL VM again:

```bash
uptime -s          # the distro had been up for ~2 days, it does not restart
free -h            # ~4 GiB free, no OOM kills
df -h / /tmp       # 1 % used
```

`sudo dmesg -T | grep -i "killed process"` finds out-of-memory kills if memory is
ever in doubt.

### Log noise that is NOT a bug

Do not chase these; they appear on every start:

- `PendingMigrationError: navigator is now a global in nodejs` — a warning from the
  built-in Copilot extension. It is logged as `[error]` but harmless.
- `Error: EEXIST: file already exists ... vscode.lock` — a stale lock file after a
  crash. It is detected and deleted automatically on the next start.
- `vscode-file: Refused to load resource ... seti.woff` — a missing icon font in the
  editor UI, unrelated to this project.
