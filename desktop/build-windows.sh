#!/bin/sh
# Builds the Windows desktop app (LabyrintheBattleboard.exe and its installer) from the web app.
# Runs on Linux (Ubuntu 24.04). Needs: rustc-1.97, cargo-1.97, rust-1.97-src, mingw-w64, nsis,
# PHP (to render the page once) and Node (for the Tauri command-line tools).
# Ubuntu doesn't ship Rust's standard library for Windows, so it is compiled here from rust-src
# (-Zbuild-std). See README-desktop.md for the one-line fix that Ubuntu's rust-src needs.
set -e
cd "$(dirname "$0")"
WEB=${WEB:-../BB}

# 1. The web app as plain files: render index.php once to HTML (no server: empty token and API).
rm -rf dist && mkdir -p dist/BB
cp -r "$WEB/assets" dist/BB/
( cd "$WEB" && php -d display_errors=stderr index.php ) \
  | sed -E 's#<meta name="csrf-token" content="[^"]*">#<meta name="csrf-token" content="">#; s#<meta name="bb-api" content="[^"]*">#<meta name="bb-api" content="">#' \
  > dist/BB/index.html
if grep -q '<?' dist/BB/index.html; then echo "PHP left in the page"; exit 1; fi

# 2. Compile the Windows app.
export RUSTC=/usr/bin/rustc-1.97 RUSTC_BOOTSTRAP=1
cd src-tauri
cargo-1.97 build --release --target x86_64-pc-windows-gnu -Zbuild-std=std,panic_abort
mkdir -p ../out
cp target/x86_64-pc-windows-gnu/release/WebView2Loader.dll ../out/
cp target/x86_64-pc-windows-gnu/release/LabyrintheBattleboard.exe ../out/ 2>/dev/null \
  || cp target/x86_64-pc-windows-gnu/release/labyrinthe-battleboard.exe ../out/LabyrintheBattleboard.exe

# 3. The installer.
cd ..
makensis -V2 installer.nsi
ls -la out
