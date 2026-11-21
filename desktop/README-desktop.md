# Labyrinthe Battleboard Generator — Windows desktop app (Tauri)

The website's generator (`BB/`) in its own Windows window: no browser, no command window, no PHP.

## What the user gets

- `LabyrintheBattleboard-Setup-3.1.0.exe` is an installer that needs no administrator rights. It
  installs into `%LOCALAPPDATA%\Programs\Labyrinthe Battleboard Generator`, adds a Start-menu
  shortcut (and a desktop one if wanted), and registers an uninstaller in Settings > Apps.
- The portable version is `LabyrintheBattleboard.exe` plus `WebView2Loader.dll`. Keep the two
  together in one folder.
- It needs Windows 10 or 11 (64-bit). It uses Microsoft Edge WebView2, which is part of Windows
  11 and is installed on Windows 10 by Windows Update.

## How it works

- **The window:** Tauri shows the web app in a native window using WebView2. The page, scripts,
  styles, fonts and watermarks are compiled into the `.exe` and are never fetched from the internet.
- **The JavaScript:** everything runs exactly as on the website, including checking imported
  files (`sanitise()` in `app.js`, the twin of the PHP Sanitiser).
- **The Rust side (`src-tauri/src/main.rs`):** it adds only:
  - no console window,
  - a single running copy (opening it again focuses the existing window),
  - the `save_export` command, which shows the Windows "Save As" dialog for Export.
    `app.js` uses it when `window.__TAURI__` exists, and otherwise downloads as on the website.
- **Saved boards:** they are kept in the app's WebView2 storage under
  `%LOCALAPPDATA%\info.labynoob.battleboard`. Uninstalling leaves them in place.
- **Security:** the Content-Security-Policy in `tauri.conf.json` matches the website's. The page
  may only use Tauri's core features; it has no file-system access of its own.

## Building (from Linux, Ubuntu 24.04)

```
sudo apt install rustc-1.97 cargo-1.97 rust-1.97-src mingw-w64 nsis php-cli nodejs npm
npm install            # Tauri command-line tools (only used for `npx tauri icon`)
./build-windows.sh     # renders ../../app/BB, compiles the .exe, builds the installer into out/
```

- **Why this setup:** Ubuntu doesn't ship Rust's standard library for Windows, so the build
  compiles it from `rust-1.97-src` (`-Zbuild-std`) and links with MinGW.
- **One-time fix to Ubuntu's source package:** it leaves out one dependency that the Windows build
  of the standard library needs. Add these lines to the end of
  `/usr/src/rustc-1.97.1/library/std/Cargo.toml` (they are in the upstream Rust source):

  ```
  [target.'cfg(windows)'.dependencies]
  windows-link = { path = "../windows_link" }
  ```

- **Building on Windows instead:** install Rust (rustup), the MSVC build tools and Node, then run
  `npx tauri build` in this folder. That also makes an installer.

**Icon:** `app-icon.png` is the source image. Regenerate the icon set with
`npx tauri icon app-icon.png -o src-tauri/icons`.

**Version:** keep `version` in `src-tauri/tauri.conf.json`, `src-tauri/Cargo.toml` and
`installer.nsi`, and the window title, in step with the website's RC number. Rebuild after every
website release so the two stay identical.
