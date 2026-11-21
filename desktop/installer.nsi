; Labyrinthe Battleboard Generator - Windows installer (built with NSIS: makensis installer.nsi)
;
; Installs for the current user only, so no administrator rights are needed:
;   program files  -> %LOCALAPPDATA%\Programs\Labyrinthe Battleboard Generator
;   shortcuts      -> Start menu, and optionally the desktop
;   uninstaller    -> listed in Settings > Apps, like any other program
; Saved boards are kept by the app in its own AppData folder and are NOT removed on uninstall,
; so reinstalling or upgrading keeps them.

Unicode true
!include "MUI2.nsh"
!include "LogicLib.nsh"

!define APPNAME   "Labyrinthe Battleboard Generator"
!define VERSION   "3.2.0"
!define EXE       "LabyrintheBattleboard.exe"
!define UNINSTKEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\LabyrintheBattleboard"
; Registry key where Windows records the Microsoft Edge WebView2 Runtime (built into Windows 10/11).
!define WV2KEY    "SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}"

Name "${APPNAME}"
OutFile "out\LabyrintheBattleboard-Setup-${VERSION}.exe"
InstallDir "$LOCALAPPDATA\Programs\${APPNAME}"
InstallDirRegKey HKCU "${UNINSTKEY}" "InstallLocation"
RequestExecutionLevel user
SetCompressor /SOLID lzma
BrandingText "${APPNAME} (RC 3.2.0)"

VIProductVersion "${VERSION}.0"
VIAddVersionKey "ProductName" "${APPNAME}"
VIAddVersionKey "FileDescription" "${APPNAME} installer"
VIAddVersionKey "FileVersion" "${VERSION}"
VIAddVersionKey "ProductVersion" "${VERSION}"
VIAddVersionKey "CompanyName" "labynoob.info"
VIAddVersionKey "LegalCopyright" "${APPNAME}"

!define MUI_ICON   "src-tauri\icons\icon.ico"
!define MUI_UNICON "src-tauri\icons\icon.ico"
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TEXT "This will install the ${APPNAME} on your computer.$\r$\n$\r$\nFill in, print and save your Labyrinthe battleboards without needing an internet connection.$\r$\n$\r$\nNo administrator rights are needed."
!define MUI_FINISHPAGE_RUN "$INSTDIR\${EXE}"
!define MUI_FINISHPAGE_RUN_TEXT "Open the ${APPNAME} now"
!define MUI_FINISHPAGE_SHOWREADME ""
!define MUI_FINISHPAGE_SHOWREADME_TEXT "Add a shortcut to the desktop"
!define MUI_FINISHPAGE_SHOWREADME_FUNCTION DesktopShortcut

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"

Function DesktopShortcut
  CreateShortcut "$DESKTOP\${APPNAME}.lnk" "$INSTDIR\${EXE}"
FunctionEnd

; The app needs the Microsoft Edge WebView2 Runtime. It is part of Windows 11 and is installed
; on Windows 10 by Windows Update; if it's missing, explain how to get it.
Function .onInit
  ReadRegStr $0 HKLM "${WV2KEY}" "pv"
  ${If} $0 == ""
    ReadRegStr $0 HKCU "Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}" "pv"
  ${EndIf}
  ${If} $0 == ""
  ${OrIf} $0 == "0.0.0.0"
    MessageBox MB_OKCANCEL|MB_ICONINFORMATION "The ${APPNAME} uses Microsoft Edge WebView2, which doesn't seem to be on this computer.$\r$\n$\r$\nIt's free from Microsoft: search for $\"WebView2 Runtime$\" and install the Evergreen version, then open the generator.$\r$\n$\r$\nPress OK to carry on installing." IDOK +2
    Abort
  ${EndIf}
FunctionEnd

Section "Install"
  SetOutPath "$INSTDIR"
  File "out\${EXE}"
  File "out\WebView2Loader.dll"
  File "/oname=How to use.txt" "How to use (installed).txt"
  WriteUninstaller "$INSTDIR\Uninstall.exe"

  CreateShortcut "$SMPROGRAMS\${APPNAME}.lnk" "$INSTDIR\${EXE}"

  ; Entry in Settings > Apps
  WriteRegStr   HKCU "${UNINSTKEY}" "DisplayName" "${APPNAME}"
  WriteRegStr   HKCU "${UNINSTKEY}" "DisplayVersion" "${VERSION}"
  WriteRegStr   HKCU "${UNINSTKEY}" "Publisher" "labynoob.info"
  WriteRegStr   HKCU "${UNINSTKEY}" "DisplayIcon" "$INSTDIR\${EXE}"
  WriteRegStr   HKCU "${UNINSTKEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr   HKCU "${UNINSTKEY}" "UninstallString" "$\"$INSTDIR\Uninstall.exe$\""
  WriteRegStr   HKCU "${UNINSTKEY}" "URLInfoAbout" "https://labynoob.info/BB/"
  WriteRegDWORD HKCU "${UNINSTKEY}" "NoModify" 1
  WriteRegDWORD HKCU "${UNINSTKEY}" "NoRepair" 1
SectionEnd

Section "Uninstall"
  Delete "$INSTDIR\${EXE}"
  Delete "$INSTDIR\WebView2Loader.dll"
  Delete "$INSTDIR\How to use.txt"
  Delete "$INSTDIR\Uninstall.exe"
  RMDir  "$INSTDIR"
  Delete "$SMPROGRAMS\${APPNAME}.lnk"
  Delete "$DESKTOP\${APPNAME}.lnk"
  DeleteRegKey HKCU "${UNINSTKEY}"
SectionEnd
