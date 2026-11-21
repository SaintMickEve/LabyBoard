//! Labyrinthe Battleboard Generator — Windows desktop app.
//!
//! HOW IT WORKS
//! The generator is a web app (HTML, CSS and JavaScript). Tauri shows it in a normal app window
//! using Microsoft Edge WebView2, which is built into Windows 10 and 11. The pages are packed inside
//! this .exe, so nothing needs installing and nothing is fetched from the internet.
//!
//! The JavaScript does all the real work, exactly as on the website (including checking imported
//! files). This Rust side only adds what a desktop app needs:
//!   - no console window (the `windows_subsystem` line below),
//!   - a single copy: opening the app again just brings the existing window to the front,
//!   - a proper Windows "Save As" dialog for Export (the `save_export` command) and for
//!     Save PDF (the `save_pdf` command, which receives the finished PDF's bytes).
//! Saved boards live in the app's own WebView2 storage in the user's AppData folder, so they are
//! still there next time the app is opened.

// Release builds are Windows GUI programs: no black command window.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use tauri::Manager;
use tauri_plugin_dialog::DialogExt;

/// Exported files are small (the biggest possible board is about 23 KB); refuse anything absurd.
const MAX_EXPORT_BYTES: usize = 1024 * 1024;

/// Keeps a suggested file name to safe characters, so it can't point outside the chosen folder.
fn safe_file_name(name: &str) -> String {
    let cleaned: String = name
        .chars()
        .filter(|c| c.is_alphanumeric() || matches!(c, '-' | '_' | '.' | ' '))
        .take(120)
        .collect();
    let cleaned = cleaned.trim().trim_start_matches('.').to_string();
    let base = if cleaned.is_empty() { "character-battleboard".to_string() } else { cleaned };
    if base.to_lowercase().ends_with(".json") { base } else { format!("{base}.json") }
}

/// Called by Export in assets/js/app.js. Shows the Windows "Save As" dialog, then writes the
/// character file where the user chose. Returns the saved file's name, or nothing if cancelled.
#[tauri::command]
async fn save_export(app: tauri::AppHandle, file_name: String, contents: String) -> Result<Option<String>, String> {
    if contents.len() > MAX_EXPORT_BYTES {
        return Err("the board is too large to export".into());
    }
    let chosen = app
        .dialog()
        .file()
        .set_title("Export battleboard")
        .set_file_name(safe_file_name(&file_name))
        .add_filter("Battleboard file (JSON)", &["json"])
        .blocking_save_file();
    let Some(chosen) = chosen else { return Ok(None) }; // the user pressed Cancel
    let path = chosen.into_path().map_err(|e| e.to_string())?;
    std::fs::write(&path, contents).map_err(|e| e.to_string())?;
    Ok(Some(
        path.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default(),
    ))
}

/// The PDF's suggested name: safe characters only, ending in .pdf.
fn safe_pdf_name(name: &str) -> String {
    let cleaned: String = name
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.'))
        .take(120)
        .collect();
    let cleaned = cleaned.trim_start_matches('.').to_string();
    let base = if cleaned.is_empty() { "character-battleboard".to_string() } else { cleaned };
    if base.to_lowercase().ends_with(".pdf") { base } else { format!("{base}.pdf") }
}

/// PDFs are a few megabytes (three pages at 300 dpi); refuse anything absurd.
const MAX_PDF_BYTES: usize = 64 * 1024 * 1024;

/// Called by Save PDF in assets/js/app.js with the finished PDF as raw bytes (its suggested name in
/// the `x-file-name` header). Shows the Windows "Save As" dialog and writes the file there.
#[tauri::command]
async fn save_pdf(app: tauri::AppHandle, request: tauri::ipc::Request<'_>) -> Result<Option<String>, String> {
    let tauri::ipc::InvokeBody::Raw(bytes) = request.body() else {
        return Err("no PDF was received".into());
    };
    if bytes.len() > MAX_PDF_BYTES || !bytes.starts_with(b"%PDF-") {
        return Err("that isn't a battleboard PDF".into());
    }
    let name = request
        .headers()
        .get("x-file-name")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("character-battleboard.pdf");
    let chosen = app
        .dialog()
        .file()
        .set_title("Save battleboard PDF")
        .set_file_name(safe_pdf_name(name))
        .add_filter("PDF document", &["pdf"])
        .blocking_save_file();
    let Some(chosen) = chosen else { return Ok(None) }; // the user pressed Cancel
    let path = chosen.into_path().map_err(|e| e.to_string())?;
    std::fs::write(&path, bytes).map_err(|e| e.to_string())?;
    Ok(Some(
        path.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default(),
    ))
}

fn main() {
    tauri::Builder::default()
        // Opening the app while it's already running focuses the existing window instead.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.unminimize();
                let _ = window.set_focus();
            }
        }))
        .plugin(tauri_plugin_dialog::init())
        .invoke_handler(tauri::generate_handler![save_export, save_pdf])
        .run(tauri::generate_context!())
        .expect("error while running the Labyrinthe Battleboard Generator");
}
