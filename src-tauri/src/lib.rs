use serde::{Deserialize, Serialize};
use std::path::{Path, PathBuf};
use std::process::Command;
use tauri::{AppHandle, Manager, WebviewUrl, WebviewWindowBuilder};

#[derive(Debug, Serialize)]
struct CliOutput {
    code: i32,
    stdout: String,
    stderr: String,
}

#[derive(Debug, Serialize)]
struct CustomModule {
    machine_name: String,
    name: String,
    path: String,
}

#[derive(Debug, Clone, Deserialize, Serialize, Default)]
#[serde(rename_all = "camelCase")]
struct ProviderConfig {
    provider_type: String,
    name: String,
    endpoint: String,
    model: String,
    organization: String,
    api_key: Option<String>,
    configured: bool,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
struct ProviderSummary {
    #[serde(rename = "type")]
    provider_type: String,
    name: String,
    endpoint: String,
    model: String,
    organization: String,
    configured: bool,
    api_key_configured: bool,
}

fn provider_config_path(app: &AppHandle) -> Result<PathBuf, String> {
    let directory = app
        .path()
        .app_config_dir()
        .map_err(|error| format!("No se pudo resolver la carpeta de configuración: {error}"))?;
    std::fs::create_dir_all(&directory)
        .map_err(|error| format!("No se pudo crear la carpeta de configuración: {error}"))?;
    Ok(directory.join("ai-provider.json"))
}

fn read_provider_config(app: &AppHandle) -> Option<ProviderConfig> {
    let path = provider_config_path(app).ok()?;
    let contents = std::fs::read_to_string(path).ok()?;
    serde_json::from_str(&contents).ok()
}

fn provider_summary(config: &ProviderConfig) -> ProviderSummary {
    ProviderSummary {
        provider_type: config.provider_type.clone(),
        name: config.name.clone(),
        endpoint: config.endpoint.clone(),
        model: config.model.clone(),
        organization: config.organization.clone(),
        configured: config.configured,
        api_key_configured: config
            .api_key
            .as_deref()
            .map(|value| !value.trim().is_empty())
            .unwrap_or(false),
    }
}

fn cli_candidates(app: &AppHandle) -> Vec<PathBuf> {
    let mut candidates = Vec::new();
    if let Ok(path) = std::env::var("DRUPALORG_CLI_BIN") {
        candidates.push(PathBuf::from(path));
    }
    if let Ok(resource_dir) = app.path().resource_dir() {
        candidates.push(resource_dir.join("drupalorg"));
        candidates.push(resource_dir.join("drupalorg.phar"));
    }
    if let Ok(current_dir) = std::env::current_dir() {
        candidates.push(current_dir.join("drupalorg"));
        candidates.push(current_dir.join("drupalorg.phar"));
        candidates.push(current_dir.join("..\\drupalorg"));
    }
    candidates
}

fn cli_command(app: &AppHandle, args: &[String]) -> Result<Command, String> {
    for candidate in cli_candidates(app) {
        if candidate.is_file() {
            let mut command = Command::new("php");
            command.arg(candidate);
            command.args(args);
            return Ok(command);
        }
    }

    let mut command = Command::new(if cfg!(windows) {
        "drupalorg.exe"
    } else {
        "drupalorg"
    });
    command.args(args);
    Ok(command)
}

#[tauri::command]
fn run_cli(app: AppHandle, args: Vec<String>, cwd: Option<String>) -> Result<CliOutput, String> {
    let mut command = cli_command(&app, &args)?;
    if let Some(directory) = cwd {
        command.current_dir(directory);
    }

    if let Some(config) = read_provider_config(&app) {
        command
            .env("DRUPALORG_AI_PROVIDER_TYPE", config.provider_type)
            .env("DRUPALORG_AI_PROVIDER_NAME", config.name)
            .env("DRUPALORG_AI_ENDPOINT", config.endpoint)
            .env("DRUPALORG_AI_MODEL", config.model)
            .env("DRUPALORG_AI_ORGANIZATION", config.organization);
        if let Some(api_key) = config.api_key {
            command.env("DRUPALORG_AI_API_KEY", api_key);
        }
    }

    let output = command
        .output()
        .map_err(|error| format!("No se pudo ejecutar drupalorg-cli: {error}"))?;
    Ok(CliOutput {
        code: output.status.code().unwrap_or(1),
        stdout: String::from_utf8_lossy(&output.stdout).into_owned(),
        stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
    })
}

#[tauri::command]
fn load_ai_provider(app: AppHandle) -> Option<ProviderSummary> {
    read_provider_config(&app).map(|config| provider_summary(&config))
}

#[tauri::command]
fn save_ai_provider(app: AppHandle, mut config: ProviderConfig) -> Result<ProviderSummary, String> {
    if config
        .api_key
        .as_deref()
        .map(str::trim)
        .unwrap_or("")
        .is_empty()
    {
        config.api_key = read_provider_config(&app).and_then(|old| old.api_key);
    }

    let path = provider_config_path(&app)?;
    let contents = serde_json::to_string_pretty(&config)
        .map_err(|error| format!("No se pudo serializar el provider: {error}"))?;
    std::fs::write(&path, contents)
        .map_err(|error| format!("No se pudo guardar el provider: {error}"))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let mut permissions = std::fs::metadata(&path)
            .map_err(|error| format!("No se pudieron leer los permisos: {error}"))?
            .permissions();
        permissions.set_mode(0o600);
        std::fs::set_permissions(&path, permissions)
            .map_err(|error| format!("No se pudieron proteger las credenciales: {error}"))?;
    }

    Ok(provider_summary(&config))
}

#[tauri::command]
fn clear_ai_provider(app: AppHandle) -> Result<(), String> {
    let path = provider_config_path(&app)?;
    match std::fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(error) => Err(format!("No se pudo borrar el provider: {error}")),
    }
}

#[tauri::command]
fn pick_project_directory() -> Result<Option<String>, String> {
    #[cfg(target_os = "macos")]
    let output = Command::new("osascript")
        .args([
            "-e",
            "POSIX path of (choose folder with prompt \"Selecciona la raíz del proyecto Drupal\")",
        ])
        .output()
        .map_err(|error| format!("No se pudo abrir el selector de carpetas: {error}"))?;

    #[cfg(target_os = "windows")]
    let output = Command::new("powershell")
        .args([
            "-NoProfile",
            "-Command",
            "Add-Type -AssemblyName System.Windows.Forms; $dialog = New-Object System.Windows.Forms.FolderBrowserDialog; $dialog.Description = 'Selecciona la raíz del proyecto Drupal'; if ($dialog.ShowDialog() -eq 'OK') { $dialog.SelectedPath }",
        ])
        .output()
        .map_err(|error| format!("No se pudo abrir el selector de carpetas: {error}"))?;

    #[cfg(target_os = "linux")]
    let output = Command::new("zenity")
        .args([
            "--file-selection",
            "--directory",
            "--title=Selecciona la raíz del proyecto Drupal",
        ])
        .output()
        .or_else(|_| {
            Command::new("kdialog")
                .args([
                    "--getexistingdirectory",
                    ".",
                    "Selecciona la raíz del proyecto Drupal",
                ])
                .output()
        })
        .map_err(|error| format!("No se encontró un selector de carpetas compatible: {error}"))?;

    if !output.status.success() {
        return Ok(None);
    }
    let path = String::from_utf8_lossy(&output.stdout).trim().to_string();
    Ok((!path.is_empty()).then_some(path))
}

#[tauri::command]
fn open_login_window(app: AppHandle, url: String) -> Result<(), String> {
    let parsed = url::Url::parse(&url).map_err(|error| format!("URL inválida: {error}"))?;
    if !matches!(parsed.scheme(), "https" | "http") {
        return Err("Solo se permiten URLs HTTP(S).".to_string());
    }

    let _window = WebviewWindowBuilder::new(&app, "drupal-login", WebviewUrl::External(parsed))
        .title("Iniciar sesión en Drupal.org")
        .inner_size(1100.0, 800.0)
        .build()
        .map_err(|error| format!("No se pudo abrir el navegador integrado: {error}"))?;
    Ok(())
}

#[tauri::command]
fn open_external_url(url: String) -> Result<(), String> {
    let parsed = url::Url::parse(&url).map_err(|error| format!("URL inválida: {error}"))?;
    if !matches!(parsed.scheme(), "https" | "http") {
        return Err("Solo se permiten URLs HTTP(S).".to_string());
    }

    let result = if cfg!(target_os = "macos") {
        Command::new("open").arg(&url).status()
    } else if cfg!(windows) {
        Command::new("cmd").args(["/C", "start", "", &url]).status()
    } else {
        Command::new("xdg-open").arg(&url).status()
    };
    result.map_err(|error| format!("No se pudo abrir la URL: {error}"))?;
    Ok(())
}

fn module_directory(project_path: &Path) -> Option<PathBuf> {
    [
        project_path.join("web/modules/custom"),
        project_path.join("docroot/modules/custom"),
        project_path.join("modules/custom"),
    ]
    .into_iter()
    .find(|path| path.is_dir())
}

#[tauri::command]
fn list_custom_modules(project_path: String) -> Result<Vec<CustomModule>, String> {
    let root = PathBuf::from(project_path);
    let directory = module_directory(&root)
        .ok_or_else(|| "No se encontró web/modules/custom en el proyecto.".to_string())?;
    let entries = std::fs::read_dir(&directory)
        .map_err(|error| format!("No se pudieron leer los módulos custom: {error}"))?;
    let mut modules = Vec::new();

    for entry in entries.flatten() {
        let path = entry.path();
        if !path.is_dir() {
            continue;
        }
        let machine_name = match path.file_name().and_then(|name| name.to_str()) {
            Some(value) => value.to_string(),
            None => continue,
        };
        let info_file = path.join(format!("{machine_name}.info.yml"));
        let contents = match std::fs::read_to_string(info_file) {
            Ok(value) => value,
            Err(_) => continue,
        };
        let name = contents
            .lines()
            .find_map(|line| line.strip_prefix("name:"))
            .map(|value| value.trim().trim_matches(['\'', '"']).to_string())
            .filter(|value| !value.is_empty())
            .unwrap_or_else(|| machine_name.replace('_', " "));
        modules.push(CustomModule {
            machine_name,
            name,
            path: path.to_string_lossy().into_owned(),
        });
    }
    modules.sort_by(|left, right| left.name.to_lowercase().cmp(&right.name.to_lowercase()));
    Ok(modules)
}

pub fn run() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![
            run_cli,
            load_ai_provider,
            save_ai_provider,
            clear_ai_provider,
            pick_project_directory,
            open_login_window,
            open_external_url,
            list_custom_modules
        ])
        .run(tauri::generate_context!())
        .expect("error while running Drupal Workbench");
}
