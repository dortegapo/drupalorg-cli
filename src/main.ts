import './styles.css';

type Screen = 'welcome' | 'auth' | 'provider' | 'workbench' | 'discover' | 'publish' | 'settings';
type ProviderType = 'openai-compatible' | 'drupal-ai';
type Language = 'en' | 'es' | 'fr' | 'zh-Hant';
type Project = { id: string; name: string; path: string; createdAt: string };
type Finding = { machineName: string; title: string; description: string; url: string };
type CustomModule = { machine_name: string; name: string; path: string };
type CliOutput = { code: number; stdout: string; stderr: string };
type ProviderState = {
  type: ProviderType;
  name: string;
  endpoint: string;
  model: string;
  organization: string;
  configured: boolean;
  apiKeyConfigured: boolean;
};

type AppState = {
  objective: string;
  language: Language;
  screen: Screen;
  projects: Project[];
  selectedProjectId: string | null;
  provider: ProviderState;
  auth: { checked: boolean; authenticated: boolean; message: string };
  searchTerms: string[];
  findings: Finding[];
  modules: CustomModule[];
  selectedModule: string;
  publishLog: string;
  notice: string;
};

type TauriWindow = Window & {
  __TAURI__?: { core?: { invoke: <T>(command: string, args?: Record<string, unknown>) => Promise<T> } };
};

const storageKey = 'drupal-workbench-state';
const languages: Array<{ id: Language; label: string }> = [
  { id: 'en', label: 'English' },
  { id: 'es', label: 'Español' },
  { id: 'fr', label: 'Français' },
  { id: 'zh-Hant', label: '繁體中文' },
];
const translations: Record<Language, Record<string, string>> = {
  en: {
    language: 'Language', welcomeEyebrow: 'DRUPAL.ORG WORKBENCH', welcomeTitle: 'Welcome to<br><em>Drupal Workbench</em>', welcomeBody: 'Your workspace for turning ideas into Drupal solutions: discover contributed modules, analyze custom modules with AI, and prepare them for publication on Drupal.org.', welcomeHint: 'Connect your account, add a Drupal project, and get to work.', start: 'Get started', workspace: 'WORKSPACE', noProject: 'No project', summary: 'Overview', discoverModules: 'Find modules', publishModule: 'Publish module', settings: 'Settings', workspaceLocal: 'Local workspace', step1: 'STEP 1 OF 2', step2: 'STEP 2 OF 2', connection: 'CONNECTION', connectDrupal: 'Connect Drupal.org', authText: 'Sign in to browse projects, manage issues, and prepare publications. We never store your password.', security: 'Secure authentication', authSecurityText: 'Login opens in a protected application window.', openLogin: 'Open Drupal.org login', skip: 'Skip for now', back: 'Back', continue: 'Continue', intelligence: 'INTELLIGENCE', configureProvider: 'Configure your AI provider', providerText: 'This configuration is used to review custom modules, compare overlap, and prepare publications.', providerType: 'Provider type', openaiCompatible: 'OpenAI compatible', drupalAi: 'Project Drupal AI', visibleName: 'Display name', baseUrl: 'Base URL', model: 'Model', apiKey: 'API key', savedSecurely: 'stored securely', requiredOpenAi: 'required for OpenAI compatible', keepKey: 'Leave empty to keep it', organization: 'Organization / project', optional: 'optional', useDrupalAi: 'Use the project Drupal AI configuration', providerReady: 'Provider ready for analysis and publishing', providerIncomplete: 'Complete the details to enable assisted analysis', saveProvider: 'Save and open workbench', workbench: 'Your workbench', overview: 'OVERVIEW', projects: 'PROJECTS', projectDesc: 'Select a project to activate the Drupal tools.', addProject: 'Add project', addFirstProject: 'Add your first project', selectRoot: 'Select the root of your Drupal installation', findDrupal: 'Find Drupal modules', discovery: 'DISCOVERY', findSolidBase: 'Find a solid foundation.', discoverDesc: 'Describe your business case and compare Drupal.org with your needs.', live: 'Drupal.org live', businessCase: 'BUSINESS CASE', concepts: 'concepts', termPlaceholder: 'Add a need and press Enter...', searchSolutions: 'Search solutions', findings: 'Findings', resultsHere: 'Results will appear here', foundProjects: 'projects found', sort: 'Sort: relevance ▾', assistedReview: 'Assisted review', providerConnected: 'Provider connected', providerPending: 'Provider pending', searchStep: 'Drupal.org receives your search terms.', compareStep: 'Projects are ranked by relevance.', analyzeStep: 'Configured AI reviews the module context when publishing.', privacy: 'Only search terms are sent. Code is analyzed locally or with your configured provider.', contribution: 'CONTRIBUTION', takeModule: 'Bring your module to the ecosystem.', publishDesc: 'We will check for overlap with a contributed project before preparing publication.', draft: 'DRAFT', selectModule: 'Select a module', addProjectFirst: 'Add a project from the workbench', scanModules: 'Read custom modules', moduleToPublish: 'Module to publish', scanFirst: 'Scan custom modules first', analyzeAdvance: 'Analyze and continue', analysisUsesProvider: 'your configured AI provider and Drupal.org', analysisUsesDrupal: 'Drupal.org; configure a provider to enable assisted review', publicationFlow: 'PUBLICATION FLOW', detectModule: 'Detect module', readInfo: 'Read info.yml and Composer context', reviewOverlap: 'Review overlap', searchSimilar: 'Search similar Drupal.org projects', prepareHandoff: 'Prepare handoff', metadata: 'Metadata, repository, and publication', account: 'ACCOUNT', checkConnection: 'Check connection', ai: 'INTELLIGENCE', notConfigured: 'Provider not configured', addModel: 'Add a model to enable assisted reviews.', connected: '● Connected', pendingConfig: '○ Configuration pending', data: 'DATA', workspaceData: 'Local workspace', reset: 'Reset preferences', selectorTitle: 'Select workspace', noProjects: 'No projects yet', addProjectMenu: 'Add a project', searchBusy: 'Searching…', publishBusy: 'Analyzing…', searchPreview: 'Local preview: run the Tauri app to query Drupal.org live.', searchDone: 'projects found on Drupal.org.', publishDone: 'Publication analysis completed.', publishFailed: 'The publication analysis could not be completed.', selectFolder: 'Select a folder to add the project.', noCli: 'The CLI is not available in this browser view.', noModule: 'Select a module before continuing.', projectRequired: 'Add or select a project first.', noSearch: 'Add at least one business concept to search.', loginOpened: 'Drupal.org login opened. When finished, continue or check the connection from Settings.', providerSaved: 'Provider saved securely.', modulesFound: 'custom modules found.', modulesFailed: 'Modules could not be read. Check the path and Drupal installation.', cliUnavailable: 'The packaged CLI is not available in this view.'
  },
  es: {
    language: 'Idioma', welcomeEyebrow: 'DRUPAL.ORG WORKBENCH', welcomeTitle: 'Bienvenido a<br><em>Drupal Workbench</em>', welcomeBody: 'Tu espacio de trabajo para convertir ideas en soluciones Drupal: descubre módulos contrib, analiza módulos custom con IA y prepáralos para publicar en Drupal.org.', welcomeHint: 'Conecta tu cuenta, añade un proyecto Drupal y empieza a trabajar.', start: 'Comenzar', workspace: 'WORKSPACE', noProject: 'Sin proyecto', summary: 'Resumen', discoverModules: 'Buscar módulos', publishModule: 'Publicar módulo', settings: 'Ajustes', workspaceLocal: 'Workspace local', step1: 'PASO 1 DE 2', step2: 'PASO 2 DE 2', connection: 'CONEXIÓN', connectDrupal: 'Conecta Drupal.org', authText: 'Accede a tu cuenta para consultar proyectos, gestionar issues y preparar publicaciones. Nunca guardamos tu contraseña.', security: 'Autenticación segura', authSecurityText: 'El login se abre en una ventana protegida de la aplicación.', openLogin: 'Abrir login de Drupal.org', skip: 'Omitir por ahora', back: 'Volver', continue: 'Continuar', intelligence: 'INTELIGENCIA', configureProvider: 'Configura tu provider de IA', providerText: 'Esta configuración se usará para revisar módulos custom, comparar overlap y preparar publicaciones.', providerType: 'Tipo de provider', openaiCompatible: 'OpenAI compatible', drupalAi: 'Drupal AI del proyecto', visibleName: 'Nombre visible', baseUrl: 'Base URL', model: 'Modelo', apiKey: 'API key', savedSecurely: 'guardada de forma segura', requiredOpenAi: 'necesaria para OpenAI compatible', keepKey: 'Dejar vacío para conservarla', organization: 'Organización / proyecto', optional: 'opcional', useDrupalAi: 'Usar la configuración de Drupal AI del proyecto', providerReady: 'Provider listo para análisis y publicación', providerIncomplete: 'Completa los datos para activar el análisis asistido', saveProvider: 'Guardar y entrar al workbench', workbench: 'Tu workbench', overview: 'VISIÓN GENERAL', projects: 'PROYECTOS', projectDesc: 'Selecciona un proyecto para activar las herramientas Drupal.', addProject: 'Añadir proyecto', addFirstProject: 'Añade tu primer proyecto', selectRoot: 'Selecciona la raíz de tu instalación Drupal', findDrupal: 'Buscar módulos Drupal', discovery: 'DESCUBRIMIENTO', findSolidBase: 'Encuentra una base sólida.', discoverDesc: 'Describe tu caso de negocio y contrastaremos Drupal.org con tus necesidades.', live: 'Drupal.org live', businessCase: 'CASO DE NEGOCIO', concepts: 'conceptos', termPlaceholder: 'Añade una necesidad y pulsa Enter...', searchSolutions: 'Buscar soluciones', findings: 'Findings', resultsHere: 'Los resultados aparecerán aquí', foundProjects: 'proyectos encontrados', sort: 'Orden: relevancia ▾', assistedReview: 'Revisión asistida', providerConnected: 'Provider conectado', providerPending: 'Provider pendiente', searchStep: 'Drupal.org recibe los términos de tu caso.', compareStep: 'El motor ordena proyectos por relevancia.', analyzeStep: 'La IA configurada revisa el contexto del módulo al publicar.', privacy: 'Solo enviamos términos de búsqueda. El código se analiza localmente o con tu provider configurado.', contribution: 'CONTRIBUCIÓN', takeModule: 'Lleva tu módulo al ecosistema.', publishDesc: 'Revisaremos si existe overlap con un proyecto contrib antes de preparar la publicación.', draft: 'BORRADOR', selectModule: 'Selecciona un módulo', addProjectFirst: 'Añade un proyecto en el workbench', scanModules: 'Leer módulos custom', moduleToPublish: 'Módulo a publicar', scanFirst: 'Escanea primero los módulos custom', analyzeAdvance: 'Analizar y avanzar', analysisUsesProvider: 'tu provider de IA configurado y Drupal.org', analysisUsesDrupal: 'Drupal.org; configura un provider para activar la revisión asistida', publicationFlow: 'FLUJO DE PUBLICACIÓN', detectModule: 'Detectar módulo', readInfo: 'Leer info.yml y contexto Composer', reviewOverlap: 'Revisar overlap', searchSimilar: 'Buscar proyectos Drupal.org similares', prepareHandoff: 'Preparar handoff', metadata: 'Metadata, repositorio y publicación', account: 'CUENTA', checkConnection: 'Comprobar conexión', ai: 'INTELIGENCIA', notConfigured: 'Provider no configurado', addModel: 'Añade un modelo para activar revisiones asistidas.', connected: '● Conectado', pendingConfig: '○ Pendiente de configuración', data: 'DATOS', workspaceData: 'Workspace local', reset: 'Restablecer preferencias', selectorTitle: 'Selecciona workspace', noProjects: 'Aún no hay proyectos', addProjectMenu: 'Añadir proyecto', searchBusy: 'Buscando…', publishBusy: 'Analizando…', searchPreview: 'Vista previa local: ejecuta la app Tauri para consultar Drupal.org en directo.', searchDone: 'proyectos encontrados en Drupal.org.', publishDone: 'Análisis de publicación terminado.', publishFailed: 'No se pudo completar el análisis de publicación.', selectFolder: 'Selecciona una carpeta para añadir el proyecto.', noCli: 'El CLI no está disponible en esta vista de navegador.', noModule: 'Selecciona un módulo antes de avanzar.', projectRequired: 'Añade o selecciona primero un proyecto.', noSearch: 'Añade al menos un concepto de negocio a la búsqueda.', loginOpened: 'Se ha abierto el login de Drupal.org. Cuando termines, continúa o comprueba la conexión desde Ajustes.', providerSaved: 'Provider guardado de forma segura.', modulesFound: 'módulos custom encontrados.', modulesFailed: 'No se pudieron leer los módulos. Revisa la ruta y la instalación Drupal.', cliUnavailable: 'El CLI empaquetado no está disponible en esta vista.'
  },
  fr: {
    language: 'Langue', welcomeEyebrow: 'DRUPAL.ORG WORKBENCH', welcomeTitle: 'Bienvenue dans<br><em>Drupal Workbench</em>', welcomeBody: 'Votre espace pour transformer des idées en solutions Drupal : découvrez les modules contrib, analysez vos modules custom avec l’IA et préparez leur publication sur Drupal.org.', welcomeHint: 'Connectez votre compte, ajoutez un projet Drupal et commencez.', start: 'Commencer', workspace: 'ESPACE DE TRAVAIL', noProject: 'Aucun projet', summary: 'Résumé', discoverModules: 'Rechercher des modules', publishModule: 'Publier un module', settings: 'Paramètres', workspaceLocal: 'Espace local', step1: 'ÉTAPE 1 SUR 2', step2: 'ÉTAPE 2 SUR 2', connection: 'CONNEXION', connectDrupal: 'Connecter Drupal.org', authText: 'Connectez-vous pour consulter les projets, gérer les issues et préparer des publications. Nous ne conservons jamais votre mot de passe.', security: 'Authentification sécurisée', authSecurityText: 'La connexion s’ouvre dans une fenêtre protégée.', openLogin: 'Ouvrir la connexion Drupal.org', skip: 'Passer pour le moment', back: 'Retour', continue: 'Continuer', intelligence: 'INTELLIGENCE', configureProvider: 'Configurer votre provider IA', providerText: 'Cette configuration servira à examiner les modules custom, comparer les chevauchements et préparer les publications.', providerType: 'Type de provider', openaiCompatible: 'Compatible OpenAI', drupalAi: 'Drupal AI du projet', visibleName: 'Nom affiché', baseUrl: 'URL de base', model: 'Modèle', apiKey: 'Clé API', savedSecurely: 'enregistrée en sécurité', requiredOpenAi: 'requise pour OpenAI compatible', keepKey: 'Laisser vide pour la conserver', organization: 'Organisation / projet', optional: 'optionnel', useDrupalAi: 'Utiliser la configuration Drupal AI du projet', providerReady: 'Provider prêt pour l’analyse et la publication', providerIncomplete: 'Complétez les champs pour activer l’analyse assistée', saveProvider: 'Enregistrer et ouvrir l’espace', workbench: 'Votre espace de travail', overview: 'VUE D’ENSEMBLE', projects: 'PROJETS', projectDesc: 'Sélectionnez un projet pour activer les outils Drupal.', addProject: 'Ajouter un projet', addFirstProject: 'Ajouter votre premier projet', selectRoot: 'Sélectionnez la racine de votre installation Drupal', findDrupal: 'Rechercher des modules Drupal', discovery: 'DÉCOUVERTE', findSolidBase: 'Trouvez une base solide.', discoverDesc: 'Décrivez votre besoin et comparez-le aux projets Drupal.org.', live: 'Drupal.org en direct', businessCase: 'BESOIN MÉTIER', concepts: 'concepts', termPlaceholder: 'Ajoutez un besoin puis appuyez sur Entrée…', searchSolutions: 'Rechercher des solutions', findings: 'Résultats', resultsHere: 'Les résultats apparaîtront ici', foundProjects: 'projets trouvés', sort: 'Tri : pertinence ▾', assistedReview: 'Revue assistée', providerConnected: 'Provider connecté', providerPending: 'Provider en attente', searchStep: 'Drupal.org reçoit vos termes de recherche.', compareStep: 'Les projets sont classés par pertinence.', analyzeStep: 'L’IA configurée examine le contexte du module à la publication.', privacy: 'Seuls les termes de recherche sont envoyés. Le code est analysé localement ou avec votre provider.', contribution: 'CONTRIBUTION', takeModule: 'Amenez votre module dans l’écosystème.', publishDesc: 'Nous vérifierons les chevauchements avec un projet contrib avant la publication.', draft: 'BROUILLON', selectModule: 'Sélectionnez un module', addProjectFirst: 'Ajoutez un projet depuis l’espace de travail', scanModules: 'Lire les modules custom', moduleToPublish: 'Module à publier', scanFirst: 'Scannez d’abord les modules custom', analyzeAdvance: 'Analyser et continuer', analysisUsesProvider: 'votre provider IA et Drupal.org', analysisUsesDrupal: 'Drupal.org ; configurez un provider pour activer la revue', publicationFlow: 'PROCESSUS DE PUBLICATION', detectModule: 'Détecter le module', readInfo: 'Lire info.yml et le contexte Composer', reviewOverlap: 'Vérifier les chevauchements', searchSimilar: 'Rechercher des projets Drupal.org similaires', prepareHandoff: 'Préparer le handoff', metadata: 'Métadonnées, dépôt et publication', account: 'COMPTE', checkConnection: 'Vérifier la connexion', ai: 'INTELLIGENCE', notConfigured: 'Provider non configuré', addModel: 'Ajoutez un modèle pour activer les revues assistées.', connected: '● Connecté', pendingConfig: '○ Configuration en attente', data: 'DONNÉES', workspaceData: 'Espace local', reset: 'Réinitialiser les préférences', selectorTitle: 'Sélectionner un espace', noProjects: 'Aucun projet pour le moment', addProjectMenu: 'Ajouter un projet', searchBusy: 'Recherche…', publishBusy: 'Analyse…', searchPreview: 'Aperçu local : lancez l’application Tauri pour interroger Drupal.org.', searchDone: 'projets trouvés sur Drupal.org.', publishDone: 'Analyse de publication terminée.', publishFailed: 'L’analyse de publication a échoué.', selectFolder: 'Sélectionnez un dossier pour ajouter le projet.', noCli: 'Le CLI n’est pas disponible dans cette vue navigateur.', noModule: 'Sélectionnez un module avant de continuer.', projectRequired: 'Ajoutez ou sélectionnez d’abord un projet.', noSearch: 'Ajoutez au moins un concept métier.', loginOpened: 'La connexion Drupal.org est ouverte. Continuez ou vérifiez la connexion dans Paramètres.', providerSaved: 'Provider enregistré en sécurité.', modulesFound: 'modules custom trouvés.', modulesFailed: 'Impossible de lire les modules. Vérifiez le chemin et l’installation Drupal.', cliUnavailable: 'Le CLI empaqueté n’est pas disponible dans cette vue.'
  },
  'zh-Hant': {
    language: '語言', welcomeEyebrow: 'DRUPAL.ORG WORKBENCH', welcomeTitle: '歡迎使用<br><em>Drupal Workbench</em>', welcomeBody: '將想法轉化為 Drupal 解決方案的工作空間：探索貢獻模組、使用 AI 分析自訂模組，並準備發佈到 Drupal.org。', welcomeHint: '連結帳戶、加入 Drupal 專案，然後開始工作。', start: '開始使用', workspace: '工作區', noProject: '沒有專案', summary: '總覽', discoverModules: '尋找模組', publishModule: '發佈模組', settings: '設定', workspaceLocal: '本機工作區', step1: '步驟 1／2', step2: '步驟 2／2', connection: '連線', connectDrupal: '連結 Drupal.org', authText: '登入以瀏覽專案、管理 issue 並準備發佈。我們不會儲存您的密碼。', security: '安全驗證', authSecurityText: '登入會在受保護的應用程式視窗中開啟。', openLogin: '開啟 Drupal.org 登入', skip: '稍後再做', back: '返回', continue: '繼續', intelligence: '智慧功能', configureProvider: '設定 AI provider', providerText: '此設定會用於檢查自訂模組、比較功能重疊並準備發佈。', providerType: 'Provider 類型', openaiCompatible: 'OpenAI 相容', drupalAi: '專案 Drupal AI', visibleName: '顯示名稱', baseUrl: 'Base URL', model: '模型', apiKey: 'API key', savedSecurely: '已安全儲存', requiredOpenAi: 'OpenAI 相容 provider 必填', keepKey: '留空以保留現有金鑰', organization: '組織／專案', optional: '選填', useDrupalAi: '使用專案的 Drupal AI 設定', providerReady: 'Provider 已準備好進行分析與發佈', providerIncomplete: '完成資料以啟用輔助分析', saveProvider: '儲存並進入工作區', workbench: '您的工作區', overview: '總覽', projects: '專案', projectDesc: '選取專案以啟用 Drupal 工具。', addProject: '新增專案', addFirstProject: '新增第一個專案', selectRoot: '選取 Drupal 安裝的根目錄', findDrupal: '尋找 Drupal 模組', discovery: '探索', findSolidBase: '找到穩固的基礎。', discoverDesc: '描述您的業務需求，並與 Drupal.org 比較。', live: 'Drupal.org 即時', businessCase: '業務需求', concepts: '個概念', termPlaceholder: '新增需求後按 Enter…', searchSolutions: '搜尋解決方案', findings: '結果', resultsHere: '結果會顯示在這裡', foundProjects: '個專案', sort: '排序：相關性 ▾', assistedReview: '輔助審查', providerConnected: 'Provider 已連線', providerPending: 'Provider 尚未設定', searchStep: 'Drupal.org 會收到您的搜尋詞。', compareStep: '專案會依相關性排序。', analyzeStep: '發佈時，已設定的 AI 會檢查模組內容。', privacy: '只會傳送搜尋詞。程式碼會在本機或使用您設定的 provider 分析。', contribution: '貢獻', takeModule: '讓您的模組加入 Drupal 生態系。', publishDesc: '準備發佈前，我們會檢查是否與現有貢獻專案重疊。', draft: '草稿', selectModule: '選取模組', addProjectFirst: '請先從工作區新增專案', scanModules: '讀取自訂模組', moduleToPublish: '要發佈的模組', scanFirst: '請先掃描自訂模組', analyzeAdvance: '分析並繼續', analysisUsesProvider: '您設定的 AI provider 與 Drupal.org', analysisUsesDrupal: 'Drupal.org；設定 provider 以啟用輔助審查', publicationFlow: '發佈流程', detectModule: '偵測模組', readInfo: '讀取 info.yml 與 Composer 內容', reviewOverlap: '檢查重疊', searchSimilar: '搜尋相似的 Drupal.org 專案', prepareHandoff: '準備交接', metadata: '中繼資料、儲存庫與發佈', account: '帳戶', checkConnection: '檢查連線', ai: '智慧功能', notConfigured: 'Provider 尚未設定', addModel: '新增模型以啟用輔助審查。', connected: '● 已連線', pendingConfig: '○ 等待設定', data: '資料', workspaceData: '本機工作區', reset: '重設偏好設定', selectorTitle: '選取工作區', noProjects: '尚無專案', addProjectMenu: '新增專案', searchBusy: '搜尋中…', publishBusy: '分析中…', searchPreview: '本機預覽：請執行 Tauri 應用程式以即時查詢 Drupal.org。', searchDone: '個 Drupal.org 專案。', publishDone: '發佈分析完成。', publishFailed: '無法完成發佈分析。', selectFolder: '選取資料夾以新增專案。', noCli: '此瀏覽器檢視無法使用 CLI。', noModule: '請先選取模組。', projectRequired: '請先新增或選取專案。', noSearch: '請至少新增一個業務概念。', loginOpened: 'Drupal.org 登入已開啟。完成後可繼續，或在設定中檢查連線。', providerSaved: 'Provider 已安全儲存。', modulesFound: '個自訂模組。', modulesFailed: '無法讀取模組，請檢查路徑與 Drupal 安裝。', cliUnavailable: '此檢視無法使用已封裝的 CLI。'
  },
};

const uiExtras: Record<Language, Record<string, string>> = {
  en: {
    editProvider: 'Edit provider',
    saveChanges: 'Save changes',
    close: 'Close',
    debugOnly: 'Development purposes only: this clears local workspaces, provider metadata, and onboarding preferences for debugging.',
    resetWarning: 'This is a development/debugging action. It removes the preferences stored by this app from this device.',
    cancel: 'Cancel',
    confirmReset: 'Reset preferences',
    resetDone: 'Preferences reset.',
    browserAuth: 'The browser preview cannot inspect the desktop session. Drupal.org login has been opened; check the connection from the Tauri app afterwards.',
    authRequired: 'No active Drupal.org session was found. Login has been opened; check the connection again when you finish.',
    authUnavailable: 'The CLI session could not be checked. Login has been opened; check the connection again when you finish.',
  },
  es: {
    editProvider: 'Editar provider',
    saveChanges: 'Guardar cambios',
    close: 'Cerrar',
    debugOnly: 'Solo para desarrollo: borra los workspaces locales, los metadatos del provider y las preferencias iniciales para depuración.',
    resetWarning: 'Esta es una acción de desarrollo/debugging. Elimina las preferencias que esta aplicación guarda en este dispositivo.',
    cancel: 'Cancelar',
    confirmReset: 'Restablecer preferencias',
    resetDone: 'Preferencias restablecidas.',
    browserAuth: 'La vista de navegador no puede consultar la sesión de escritorio. Se ha abierto el login de Drupal.org; después comprueba la conexión desde la app Tauri.',
    authRequired: 'No se ha encontrado una sesión activa de Drupal.org. Se ha abierto el login; vuelve a comprobar la conexión al terminar.',
    authUnavailable: 'No se pudo comprobar la sesión del CLI. Se ha abierto el login; vuelve a comprobar la conexión al terminar.',
  },
  fr: {
    editProvider: 'Modifier le provider',
    saveChanges: 'Enregistrer les modifications',
    close: 'Fermer',
    debugOnly: 'Réservé au développement : efface les espaces locaux, les métadonnées du provider et les préférences pour le débogage.',
    resetWarning: 'Cette action de développement/debugging supprime les préférences enregistrées par cette application sur cet appareil.',
    cancel: 'Annuler',
    confirmReset: 'Réinitialiser les préférences',
    resetDone: 'Préférences réinitialisées.',
    browserAuth: 'L’aperçu navigateur ne peut pas consulter la session de bureau. La connexion Drupal.org est ouverte ; vérifiez ensuite depuis l’application Tauri.',
    authRequired: 'Aucune session Drupal.org active. La connexion est ouverte ; vérifiez à nouveau après la connexion.',
    authUnavailable: 'La session CLI n’a pas pu être vérifiée. La connexion est ouverte ; vérifiez à nouveau après la connexion.',
  },
  'zh-Hant': {
    editProvider: '編輯 provider',
    saveChanges: '儲存變更',
    close: '關閉',
    debugOnly: '僅供開發使用：會清除本機工作區、provider 資訊與初始設定，以便除錯。',
    resetWarning: '這是開發／除錯功能，會刪除此裝置上由本應用程式儲存的偏好設定。',
    cancel: '取消',
    confirmReset: '重設偏好設定',
    resetDone: '偏好設定已重設。',
    browserAuth: '瀏覽器預覽無法檢查桌面工作階段。已開啟 Drupal.org 登入，完成後請從 Tauri 應用程式檢查連線。',
    authRequired: '找不到有效的 Drupal.org 工作階段。已開啟登入，完成後請再次檢查連線。',
    authUnavailable: '無法檢查 CLI 工作階段。已開啟登入，完成後請再次檢查連線。',
  },
};

const appRoot = document.querySelector<HTMLDivElement>('#app');
if (!appRoot) throw new Error('Application root is missing.');
const app: HTMLDivElement = appRoot;

const defaultState: AppState = {
  objective: translations.en.welcomeBody,
  language: 'en',
  screen: 'welcome',
  projects: [],
  selectedProjectId: null,
  provider: {
    type: 'openai-compatible',
    name: '',
    endpoint: 'https://api.openai.com/v1',
    model: 'gpt-4o-mini',
    organization: '',
    configured: false,
    apiKeyConfigured: false,
  },
  auth: { checked: false, authenticated: false, message: 'Connection has not been checked yet.' },
  searchTerms: [],
  findings: [],
  modules: [],
  selectedModule: '',
  publishLog: '',
  notice: '',
};

function loadState(): AppState {
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey) ?? '{}') as Partial<AppState>;
    const language = languages.some((option) => option.id === stored.language) ? stored.language as Language : 'en';
    return {
      ...defaultState,
      ...stored,
      language,
      objective: typeof stored.objective === 'string' && stored.objective.trim() !== '' ? stored.objective : welcomeObjectiveFor(language),
      provider: { ...defaultState.provider, ...(stored.provider ?? {}) },
      auth: { ...defaultState.auth, ...(stored.auth ?? {}) },
    };
  } catch {
    return { ...defaultState };
  }
}

let state = loadState();

let workspaceMenuOpen = false;
let providerEditorOpen = false;
let resetModalOpen = false;
let busyAction: 'search' | 'publish' | '' = '';

function t(key: string): string {
  return translations[state.language][key] ?? translations.en[key] ?? uiExtras[state.language][key] ?? uiExtras.en[key] ?? key;
}

function welcomeObjectiveFor(language: Language): string {
  return translations[language].welcomeBody;
}

function persist(): void {
  // API keys are deliberately never kept in localStorage. Tauri stores them in its config directory.
  localStorage.setItem(storageKey, JSON.stringify(state));
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character] ?? character);
}

function invoke<T>(command: string, args: Record<string, unknown> = {}): Promise<T> {
  const tauri = window as TauriWindow;
  if (!tauri.__TAURI__?.core?.invoke) {
    return Promise.reject(new Error('Esta vista se está ejecutando en modo navegador.'));
  }
  return tauri.__TAURI__.core.invoke<T>(command, args);
}

function isTauri(): boolean {
  const tauri = window as TauriWindow;
  return Boolean(tauri.__TAURI__?.core?.invoke);
}

async function runCli(args: string[], cwd?: string): Promise<CliOutput> {
  return invoke<CliOutput>('run_cli', { args, cwd });
}

function selectedProject(): Project | undefined {
  return state.projects.find((project) => project.id === state.selectedProjectId);
}

function createProjectId(): string {
  return typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `project-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function providerReady(): boolean {
  if (state.provider.type === 'drupal-ai') return state.provider.configured;
  return Boolean(state.provider.endpoint && state.provider.model && state.provider.apiKeyConfigured);
}

function showNotice(message: string): void {
  state.notice = message;
  render();
  window.setTimeout(() => {
    if (state.notice === message) {
      state.notice = '';
      render();
    }
  }, 4200);
}

function drupalLogo(): string {
  return `<svg class="drupal-logo" viewBox="0 0 48 48" role="img" aria-label="Drupal"><path d="M24 3c1.5 5.6 4.8 8.4 9.2 11.5C38.2 18 42 22.5 42 29.2 42 38 34 45 24 45S6 38 6 29.2c0-6.7 3.8-11.2 8.8-14.7C19.2 11.4 22.5 8.6 24 3Z" fill="currentColor"/><path d="M17.1 28.7c0-4.4 3-7.9 6.9-7.9s6.9 3.5 6.9 7.9-3 7.9-6.9 7.9-6.9-3.5-6.9-7.9Zm4.3 0c0 2.1 1.1 3.7 2.6 3.7s2.6-1.6 2.6-3.7-1.1-3.7-2.6-3.7-2.6 1.6-2.6 3.7Z" fill="white"/><circle cx="17" cy="20" r="2" fill="white"/><circle cx="31" cy="20" r="2" fill="white"/></svg>`;
}

function footer(): string {
  return '<footer class="app-footer">Created by <a class="footer-link" href="https://www.drupal.org/u/dortega" data-url="https://www.drupal.org/u/dortega">dortega</a> &amp; <a class="footer-link" href="https://www.drupal.org/u/pgrandeg" data-url="https://www.drupal.org/u/pgrandeg">pgrandeg</a></footer>';
}

function sidebar(): string {
  const project = selectedProject();
  const projectNavigation = project ? `<button class="nav-item ${state.screen === 'discover' ? 'active' : ''}" data-action="go-discover"><span class="nav-icon">⌕</span> ${t('discoverModules')}</button><button class="nav-item ${state.screen === 'publish' ? 'active' : ''}" data-action="go-publish"><span class="nav-icon">↗</span> ${t('publishModule')}</button>` : '';
  const workspaceProjects = state.projects.length ? state.projects.map((item) => `<button class="workspace-option ${item.id === state.selectedProjectId ? 'selected' : ''}" data-workspace-project="${escapeHtml(item.id)}"><span class="project-dot"></span><span><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.path)}</small></span></button>`).join('') : `<div class="workspace-empty">${t('noProjects')}</div>`;
  const workspaceMenu = workspaceMenuOpen ? `<div class="workspace-menu"><div class="workspace-menu-title">${t('selectorTitle')}</div>${workspaceProjects}<button class="workspace-add" data-action="new-project">＋ ${t('addProjectMenu')}</button></div>` : '';
  return `<aside class="sidebar"><div class="brand">${drupalLogo()}<span>Drupal<br><strong>Workbench</strong></span></div><div class="workspace-label">${t('workspace')}</div><div class="workspace-selector"><button class="project-switcher" data-action="toggle-workspace" aria-expanded="${workspaceMenuOpen}"><span class="project-dot"></span><span>${escapeHtml(project?.name ?? t('noProject'))}</span><span class="chevron">⌄</span></button>${workspaceMenu}</div><nav class="main-nav"><button class="nav-item ${state.screen === 'workbench' ? 'active' : ''}" data-action="go-workbench"><span class="nav-icon">⌂</span> ${t('summary')}</button>${projectNavigation}</nav><div class="sidebar-bottom"><button class="nav-item ${state.screen === 'settings' ? 'active' : ''}" data-action="go-settings"><span class="nav-icon">⚙</span> ${t('settings')}</button><div class="account"><span class="avatar">${(state.objective || 'U').slice(0, 1).toUpperCase()}</span><span><strong>${t('workspace')}</strong><small>${state.auth.authenticated ? 'Drupal connected' : 'Local workspace'}</small></span><span class="more">•••</span></div></div></aside>`;
}

function topbar(title: string, kicker: string): string {
  return `<header class="topbar"><div><span class="eyebrow">${escapeHtml(kicker)}</span><h1>${escapeHtml(title)}</h1></div><div class="topbar-actions"><span class="sync-state"><span class="status-dot"></span> ${t('workspaceLocal')}</span><button class="icon-button" data-action="go-settings" aria-label="${t('settings')}">⚙</button></div></header>`;
}

function onboarding(): string {
  const languageOptions = languages.map((language) => `<option value="${language.id}" ${state.language === language.id ? 'selected' : ''}>${language.label}</option>`).join('');
  return `<main class="onboarding"><div class="welcome-card"><span class="eyebrow">${t('welcomeEyebrow')}</span><h1>${t('welcomeTitle')}</h1><div class="welcome-copy"><p>${escapeHtml(t('welcomeBody'))}</p><p class="welcome-hint">${t('welcomeHint')}</p></div><label class="language-picker"><span>${t('language')}</span><select id="language-select">${languageOptions}</select></label><button class="primary-button wide" data-action="start"><span>${t('start')}</span><span>→</span></button></div></main>`;
}

function authScreen(): string {
  const connectedClass = state.auth.authenticated ? 'connected' : '';
  return `<div class="app-shell wizard-shell"><section class="content-area"><div class="wizard-header"><div class="wizard-brand">${drupalLogo()} Drupal Workbench</div><span class="step-label">${t('step1')}</span></div><div class="wizard-progress"><span class="current"></span><span></span></div><div class="material-card auth-card"><div class="card-icon blue-icon">↗</div><span class="eyebrow">${t('connection')}</span><h2>${t('connectDrupal')}</h2><p>${t('authText')}</p><div class="security-card"><span class="security-icon">⌁</span><div><strong>${t('security')}</strong><small>${t('authSecurityText')}</small></div></div><button class="primary-button wide" data-action="open-login">${t('openLogin')} <span>↗</span></button><button class="text-button" data-action="skip-auth">${t('skip')}</button><div class="auth-result ${connectedClass}">${state.auth.authenticated ? `<span class="status-dot"></span>${t('connected')}` : ''}</div></div><div class="wizard-footer"><button class="text-button" data-action="back-welcome">← ${t('back')}</button><button class="secondary-button" data-action="go-provider">${t('continue')} <span>→</span></button></div></section></div>`;
}

function providerEditor(context: 'wizard' | 'settings'): string {
  const provider = state.provider;
  const settingsContext = context === 'settings';
  const submitLabel = settingsContext ? t('saveChanges') : t('saveProvider');
  const closeButton = settingsContext ? `<button class="text-button provider-close" type="button" data-action="close-provider-editor">${t('close')}</button>` : '';
  const skipButton = settingsContext ? '' : `<button class="text-button" type="button" data-action="skip-provider">${t('skip')}</button>`;
  return `<div class="material-card provider-card"><div class="provider-editor-heading"><div><div class="card-icon green-icon">✦</div><span class="eyebrow">${t('intelligence')}</span><h2>${t('configureProvider')}</h2></div>${closeButton}</div><p>${t('providerText')}</p><form class="provider-form" data-form="provider" data-provider-context="${context}"><label class="field-label">${t('providerType')}<select name="type"><option value="openai-compatible" ${provider.type === 'openai-compatible' ? 'selected' : ''}>${t('openaiCompatible')}</option><option value="drupal-ai" ${provider.type === 'drupal-ai' ? 'selected' : ''}>${t('drupalAi')}</option></select></label><label class="field-label">${t('visibleName')}<input name="name" value="${escapeHtml(provider.name)}" placeholder="AI provider" autocomplete="organization"></label><div class="form-columns"><label class="field-label">${t('baseUrl')}<input name="endpoint" value="${escapeHtml(provider.endpoint)}" placeholder="https://api.openai.com/v1" autocomplete="url"></label><label class="field-label">${t('model')}<input name="model" value="${escapeHtml(provider.model)}" placeholder="gpt-4o-mini" autocomplete="off"></label></div><label class="field-label">${t('apiKey')} <span class="optional-label">${provider.apiKeyConfigured ? t('savedSecurely') : t('requiredOpenAi')}</span><input name="apiKey" type="password" placeholder="${provider.apiKeyConfigured ? t('keepKey') : 'sk-...'}" autocomplete="new-password"></label><label class="field-label">${t('organization')} <span class="optional-label">${t('optional')}</span><input name="organization" value="${escapeHtml(provider.organization)}" placeholder="org_..." autocomplete="organization"></label><label class="check-row"><input type="checkbox" name="configured" ${provider.configured ? 'checked' : ''}> ${t('useDrupalAi')}</label><div class="provider-connection"><span class="status-dot ${providerReady() ? 'ready' : ''}"></span><span>${providerReady() ? t('providerReady') : t('providerIncomplete')}</span></div><button class="primary-button wide" type="submit">${submitLabel} <span>→</span></button>${skipButton}</form></div>`;
}

function providerScreen(): string {
  return `<div class="app-shell wizard-shell"><section class="content-area"><div class="wizard-header"><div class="wizard-brand">${drupalLogo()} Drupal Workbench</div><span class="step-label">${t('step2')}</span></div><div class="wizard-progress"><span class="done"></span><span class="current"></span></div>${providerEditor('wizard')}<div class="wizard-footer"><button class="text-button" data-action="back-auth">← ${t('back')}</button></div></section></div>`;
}

function workbench(): string {
  return `<div class="app-shell"><section class="content-area main-content">${sidebar()}<div class="page-content">${topbar(t('workbench'), t('overview'))}<div class="section-heading"><div><span class="eyebrow">${t('projects')}</span><h2>${t('projects')}</h2><p>${t('projectDesc')}</p></div><button class="secondary-button" data-action="new-project">＋ ${t('addProject')}</button></div><div class="project-grid">${state.projects.length ? state.projects.map(projectCard).join('') : emptyProjectCard()}</div></div></section></div>`;
}

function projectCard(project: Project): string {
  const selected = project.id === state.selectedProjectId ? 'selected' : '';
  return `<button class="project-card ${selected}" data-project="${escapeHtml(project.id)}"><span class="project-card-icon">${project.name.slice(0, 1).toUpperCase()}</span><span><strong>${escapeHtml(project.name)}</strong><small>${escapeHtml(project.path)}</small></span><span class="card-arrow">→</span></button>`;
}

function emptyProjectCard(): string {
  return `<button class="project-card empty" data-action="new-project"><span class="plus-circle">＋</span><span><strong>${t('addFirstProject')}</strong><small>${t('selectRoot')}</small></span><span class="card-arrow">→</span></button>`;
}

function discover(): string {
  const terms = state.searchTerms.map((term) => `<span class="term-chip">${escapeHtml(term)} <button data-remove-term="${escapeHtml(term)}">×</button></span>`).join('');
  const findings = state.findings.length ? state.findings.map((finding) => `<article class="finding-card"><div class="finding-icon">${finding.machineName.slice(0, 1).toUpperCase()}</div><div class="finding-body"><div class="finding-title"><div><h4>${escapeHtml(finding.title)}</h4><span class="machine-name">${escapeHtml(finding.machineName)}</span></div><span class="match-score">MATCH</span></div><p>${escapeHtml(finding.description || 'Drupal.org project compatible with your search.')}</p><div class="finding-actions"><button class="small-button primary-small" data-finding-action="add" data-finding="${escapeHtml(finding.machineName)}">＋ Add suggestion</button><button class="small-button" data-url="${escapeHtml(finding.url)}">Drupal.org ↗</button></div></div></article>`).join('') : `<div class="empty-findings"><span>⌕</span><h3>Search for a capability</h3><p>Add terms such as “editorial workflow”, “semantic search”, or “CRM integration”.</p></div>`;
  const searchLabel = busyAction === 'search' ? t('searchBusy') : t('searchSolutions');
  return `<div class="app-shell"><section class="content-area main-content">${sidebar()}<div class="page-content">${topbar(t('findDrupal'), t('discovery'))}<div class="split-header"><div><h2>${t('findSolidBase')}</h2><p>${t('discoverDesc')}</p></div><span class="live-badge"><span class="status-dot"></span> ${t('live')}</span></div><div class="discovery-workspace"><main class="findings-column"><div class="query-box"><div class="query-label"><span>${t('businessCase')}</span><small>${state.searchTerms.length} ${t('concepts')}</small></div><div class="term-list">${terms}<input id="term-input" placeholder="${t('termPlaceholder')}" /></div><button class="primary-button search-button" data-action="search" ${busyAction === 'search' ? 'disabled' : ''}>${searchLabel} <span>⌕</span></button></div><div class="results-heading"><div><h3>${t('findings')}</h3><p>${state.findings.length ? `${state.findings.length} ${t('foundProjects')}` : t('resultsHere')}</p></div><span class="result-filter">${t('sort')}</span></div><div class="findings-list">${findings}</div></main><aside class="insight-panel"><div class="panel-heading"><span class="panel-icon">✦</span><div><strong>${t('assistedReview')}</strong><small>${providerReady() ? t('providerConnected') : t('providerPending')}</small></div></div><div class="insight-step"><span>01</span><p><strong>Search</strong><br>${t('searchStep')}</p></div><div class="insight-step"><span>02</span><p><strong>Compare</strong><br>${t('compareStep')}</p></div><div class="insight-step"><span>03</span><p><strong>Analyze</strong><br>${t('analyzeStep')}</p></div><div class="privacy-note">⌁ ${t('privacy')}</div></aside></div></div></section></div>`;
}

function publish(): string {
  const project = selectedProject();
  const moduleOptions = state.modules.length ? state.modules.map((module) => `<option value="${escapeHtml(module.path)}" ${state.selectedModule === module.path ? 'selected' : ''}>${escapeHtml(module.name)} (${escapeHtml(module.machine_name)})</option>`).join('') : `<option value="">${t('scanFirst')}</option>`;
  const publishLabel = busyAction === 'publish' ? t('publishBusy') : t('analyzeAdvance');
  return `<div class="app-shell"><section class="content-area main-content">${sidebar()}<div class="page-content">${topbar(t('publishModule'), t('contribution'))}<div class="split-header"><div><h2>${t('takeModule')}</h2><p>${t('publishDesc')}</p></div><span class="draft-badge">${t('draft')}</span></div><div class="publish-layout"><div class="publish-form-card"><div class="form-heading"><span class="publish-icon">↗</span><div><strong>${t('selectModule')}</strong><small>${project ? escapeHtml(project.path) : t('addProjectFirst')}</small></div></div><label class="field-label">Project path<input id="project-path" value="${escapeHtml(project?.path ?? '')}" placeholder="/Users/tu-nombre/Sites/mi-drupal"></label><button class="secondary-button wide" data-action="scan-modules">↻ ${t('scanModules')}</button><label class="field-label">${t('moduleToPublish')}<select id="module-select">${moduleOptions}</select></label><button class="primary-button wide" data-action="dry-run" ${state.selectedModule && busyAction !== 'publish' ? '' : 'disabled'}>${publishLabel} <span>→</span></button><div class="publish-hint">ⓘ ${providerReady() ? t('analysisUsesProvider') : t('analysisUsesDrupal')}.</div></div><div class="publication-flow"><span class="eyebrow">${t('publicationFlow')}</span><div class="flow-item active"><span>01</span><div><strong>${t('detectModule')}</strong><small>${t('readInfo')}</small></div></div><div class="flow-line"></div><div class="flow-item"><span>02</span><div><strong>${t('reviewOverlap')}</strong><small>${t('searchSimilar')}</small></div></div><div class="flow-line"></div><div class="flow-item"><span>03</span><div><strong>${t('prepareHandoff')}</strong><small>${t('metadata')}</small></div></div></div></div>${state.publishLog ? `<pre class="command-output">${escapeHtml(state.publishLog)}</pre>` : ''}</div></section></div>`;
}

function settings(): string {
  const editor = providerEditorOpen ? `<section class="settings-provider-editor">${providerEditor('settings')}</section>` : '';
  return `<div class="app-shell"><section class="content-area main-content">${sidebar()}<div class="page-content">${topbar(t('settings'), 'CONFIGURATION')}<div class="settings-grid"><div class="settings-card"><span class="card-kicker">${t('account')}</span><h3>Drupal.org</h3><p>${escapeHtml(state.auth.checked ? state.auth.message : t('authText'))}</p><button class="secondary-button" data-action="check-auth">${t('checkConnection')}</button></div><div class="settings-card"><span class="card-kicker">${t('ai')}</span><h3>${escapeHtml(state.provider.name || t('notConfigured'))}</h3><p>${escapeHtml(state.provider.model || t('addModel'))}</p><small class="settings-status">${providerReady() ? t('connected') : t('pendingConfig')}</small><button class="secondary-button" data-action="edit-provider">${t('editProvider')}</button></div><div class="settings-card"><span class="card-kicker">${t('data')}</span><h3>${t('workspaceData')}</h3><p>${t('workspaceData')}</p><small class="debug-note">${t('debugOnly')}</small><button class="text-button" data-action="confirm-reset">${t('reset')}</button></div></div>${editor}</div></section></div>`;
}

function resetModal(): string {
  return `<div class="modal-backdrop" role="presentation"><div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="reset-title"><span class="card-kicker">${t('data')}</span><h2 id="reset-title">${t('reset')}</h2><p>${t('resetWarning')}</p><div class="modal-actions"><button class="secondary-button" data-action="cancel-reset">${t('cancel')}</button><button class="primary-button" data-action="reset-preferences">${t('confirmReset')}</button></div></div></div>`;
}

function render(): void {
  const body = state.screen === 'welcome' ? onboarding() : state.screen === 'auth' ? authScreen() : state.screen === 'provider' ? providerScreen() : state.screen === 'workbench' ? workbench() : state.screen === 'discover' ? discover() : state.screen === 'publish' ? publish() : settings();
  app.innerHTML = `${body}${footer()}${resetModalOpen ? resetModal() : ''}${state.notice ? `<div class="toast">${escapeHtml(state.notice)}</div>` : ''}`;
  bindEvents();
}

function bindEvents(): void {
  document.querySelectorAll<HTMLElement>('[data-action]').forEach((element) => element.addEventListener('click', () => void action(element.dataset.action ?? '')));
  document.querySelectorAll<HTMLElement>('[data-project]').forEach((element) => element.addEventListener('click', () => {
    state.selectedProjectId = element.dataset.project ?? null;
    state.screen = 'workbench';
    workspaceMenuOpen = false;
    persist();
    render();
  }));
  document.querySelectorAll<HTMLElement>('[data-workspace-project]').forEach((element) => element.addEventListener('click', () => {
    state.selectedProjectId = element.dataset.workspaceProject ?? null;
    state.screen = 'workbench';
    workspaceMenuOpen = false;
    persist();
    render();
  }));
  document.querySelectorAll<HTMLElement>('[data-url]').forEach((element) => element.addEventListener('click', (event) => {
    if (element instanceof HTMLAnchorElement) event.preventDefault();
    void openUrl(element.dataset.url ?? '');
  }));
  document.querySelectorAll<HTMLElement>('[data-remove-term]').forEach((element) => element.addEventListener('click', () => {
    state.searchTerms = state.searchTerms.filter((term) => term !== element.dataset.removeTerm);
    persist();
    render();
  }));
  document.querySelectorAll<HTMLElement>('[data-finding-action="add"]').forEach((element) => element.addEventListener('click', () => showNotice(`Suggestion ${element.dataset.finding ?? ''} añadida al proyecto.`)));
  document.querySelector<HTMLFormElement>('[data-form="provider"]')?.addEventListener('submit', (event) => {
    event.preventDefault();
    void saveProvider(event.currentTarget as HTMLFormElement);
  });
  document.querySelector<HTMLSelectElement>('#language-select')?.addEventListener('change', (event) => {
    const language = (event.target as HTMLSelectElement).value as Language;
    if (!languages.some((option) => option.id === language)) return;
    state.language = language;
    state.objective = welcomeObjectiveFor(language);
    persist();
    render();
  });
  const termInput = document.querySelector<HTMLInputElement>('#term-input');
  termInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && termInput.value.trim()) {
      state.searchTerms = [...state.searchTerms, termInput.value.trim()];
      termInput.value = '';
      persist();
      render();
    }
  });
  document.querySelector<HTMLSelectElement>('#module-select')?.addEventListener('change', (event) => {
    state.selectedModule = (event.target as HTMLSelectElement).value;
    persist();
    render();
  });
}

async function saveProvider(formElement: HTMLFormElement): Promise<void> {
  const form = new FormData(formElement);
  const context = formElement.dataset.providerContext === 'settings' ? 'settings' : 'wizard';
  const type = String(form.get('type') ?? 'openai-compatible') as ProviderType;
  const apiKey = String(form.get('apiKey') ?? '').trim();
  const config = {
    providerType: type,
    name: String(form.get('name') ?? '').trim(),
    endpoint: String(form.get('endpoint') ?? '').trim(),
    model: String(form.get('model') ?? '').trim(),
    organization: String(form.get('organization') ?? '').trim(),
    apiKey: apiKey || null,
    configured: form.get('configured') === 'on',
  };
  const notice = t('providerSaved');
  try {
    state.provider = await invoke<ProviderState>('save_ai_provider', { config });
  } catch {
    state.provider = {
      type,
      name: config.name,
      endpoint: config.endpoint,
      model: config.model,
      organization: config.organization,
      configured: config.configured,
      apiKeyConfigured: Boolean(apiKey) || state.provider.apiKeyConfigured,
    };
  }
  providerEditorOpen = false;
  state.screen = context === 'settings' ? 'settings' : 'workbench';
  persist();
  render();
  showNotice(notice);
}

async function action(name: string): Promise<void> {
  if (name === 'start') {
    state.objective = welcomeObjectiveFor(state.language);
    state.screen = 'auth';
  } else if (name === 'toggle-workspace') {
    workspaceMenuOpen = !workspaceMenuOpen;
    render();
    return;
  } else if (name === 'back-welcome') {
    state.screen = 'welcome';
  } else if (name === 'back-auth') {
    state.screen = 'auth';
  } else if (name === 'skip-auth') {
    state.screen = 'provider';
  } else if (name === 'skip-provider') {
    state.screen = 'workbench';
  } else if (name === 'go-provider') {
    state.screen = 'provider';
  } else if (name === 'edit-provider') {
    providerEditorOpen = true;
    state.screen = 'settings';
  } else if (name === 'close-provider-editor') {
    providerEditorOpen = false;
  } else if (name === 'go-workbench') {
    state.screen = 'workbench';
  } else if (name === 'go-discover') {
    if (!selectedProject()) {
      showNotice(t('projectRequired'));
      return;
    }
    state.screen = 'discover';
  } else if (name === 'go-publish') {
    if (!selectedProject()) {
      showNotice(t('projectRequired'));
      return;
    }
    state.screen = 'publish';
  } else if (name === 'go-settings') {
    state.screen = 'settings';
  } else if (name === 'open-login') {
    await openDrupalLogin();
    showNotice(t('loginOpened'));
    return;
  } else if (name === 'check-auth') {
    if (!isTauri()) {
      state.auth = { checked: true, authenticated: false, message: t('browserAuth') };
      persist();
      await openDrupalLogin();
      showNotice(t('browserAuth'));
      return;
    }
    try {
      const result = await runCli(['auth:status', '--local']);
      state.auth = { checked: true, authenticated: result.code === 0, message: result.stdout.trim() || result.stderr.trim() || 'No se pudo comprobar la sesión.' };
      if (result.code !== 0) {
        await openDrupalLogin();
        showNotice(t('authRequired'));
        return;
      }
    } catch {
      state.auth = { checked: true, authenticated: false, message: t('authUnavailable') };
      await openDrupalLogin();
      showNotice(t('authUnavailable'));
      return;
    }
  } else if (name === 'new-project') {
    workspaceMenuOpen = false;
    let projectPath: string | null = null;
    try {
      projectPath = await invoke<string | null>('pick_project_directory');
    } catch {
      projectPath = window.prompt('Ruta de la raíz del proyecto Drupal:');
    }
    if (projectPath?.trim()) {
      const cleanPath = projectPath.trim();
      const nameFromPath = cleanPath.split(/[\\/]/).filter(Boolean).pop() ?? 'Proyecto Drupal';
      const existingProject = state.projects.find((project) => project.path === cleanPath);
      const project = existingProject ?? { id: createProjectId(), name: nameFromPath, path: cleanPath, createdAt: new Date().toISOString() };
      state.projects = existingProject ? state.projects : [...state.projects, project];
      state.selectedProjectId = project.id;
      state.screen = 'workbench';
    } else if (projectPath === null) {
      showNotice(t('selectFolder'));
      return;
    }
  } else if (name === 'search') {
    await searchProjects();
    return;
  } else if (name === 'scan-modules') {
    await scanModules();
    return;
  } else if (name === 'dry-run') {
    await publishDryRun();
    return;
  } else if (name === 'confirm-reset') {
    resetModalOpen = true;
    render();
    return;
  } else if (name === 'cancel-reset') {
    resetModalOpen = false;
    render();
    return;
  } else if (name === 'reset-preferences') {
    try {
      await invoke('clear_ai_provider');
    } catch {
      // Browser preview has no secure provider storage to clear.
    }
    localStorage.removeItem(storageKey);
    state = { ...defaultState };
    workspaceMenuOpen = false;
    providerEditorOpen = false;
    resetModalOpen = false;
    persist();
    render();
    showNotice(t('resetDone'));
    return;
  }
  persist();
  render();
}

async function searchProjects(): Promise<void> {
  const pendingTerm = document.querySelector<HTMLInputElement>('#term-input')?.value.trim() ?? '';
  if (pendingTerm && !state.searchTerms.includes(pendingTerm)) {
    state.searchTerms = [...state.searchTerms, pendingTerm];
  }
  const query = state.searchTerms.join(', ');
  if (!query) {
    showNotice(t('noSearch'));
    return;
  }
  busyAction = 'search';
  persist();
  render();
  let notice = t('searchPreview');
  try {
    const output = await runCli(['project:search', query, '--format=json', '--limit=10']);
    if (output.code !== 0) throw new Error(output.stderr || output.stdout);
    const payload = JSON.parse(output.stdout) as { projects?: Finding[] };
    state.findings = payload.projects ?? [];
    notice = `${state.findings.length} ${t('searchDone')}`;
  } catch {
    state.findings = [{ machineName: 'search-api', title: 'Search API', description: 'Infraestructura de búsqueda extensible para Drupal.', url: 'https://www.drupal.org/project/search_api' }, { machineName: 'ai', title: 'Artificial Intelligence', description: 'Herramientas para integrar providers y capacidades de IA en Drupal.', url: 'https://www.drupal.org/project/ai' }];
    notice = t('searchPreview');
  }
  busyAction = '';
  persist();
  showNotice(notice);
}

async function scanModules(): Promise<void> {
  const input = document.querySelector<HTMLInputElement>('#project-path');
  const projectPath = input?.value.trim() || selectedProject()?.path;
  if (!projectPath) {
    showNotice(t('selectRoot'));
    return;
  }
  try {
    state.modules = await invoke<CustomModule[]>('list_custom_modules', { projectPath });
    state.selectedModule = state.modules[0]?.path ?? '';
    showNotice(`${state.modules.length} ${t('modulesFound')}`);
  } catch {
    state.modules = [];
    showNotice(t('modulesFailed'));
  }
  persist();
  render();
}

async function publishDryRun(): Promise<void> {
  const project = selectedProject();
  if (!project) {
    showNotice(t('projectRequired'));
    return;
  }
  if (!state.selectedModule) {
    showNotice(t('noModule'));
    return;
  }
  busyAction = 'publish';
  state.publishLog = `${t('publishBusy')}\n`;
  persist();
  render();
  let notice = t('publishDone');
  try {
    const output = await runCli(['project:publish', state.selectedModule, '--dry-run', '--no-browser'], project.path);
    if (output.code !== 0) throw new Error(output.stderr || output.stdout || t('publishFailed'));
    state.publishLog = output.stdout || output.stderr || t('publishDone');
  } catch (error) {
    state.publishLog = error instanceof Error ? error.message : t('publishFailed');
    notice = t('publishFailed');
  }
  busyAction = '';
  persist();
  showNotice(notice);
}

async function openUrl(url: string): Promise<void> {
  try {
    await invoke('open_external_url', { url });
  } catch {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

async function openDrupalLogin(): Promise<void> {
  const url = 'https://www.drupal.org/user/login';
  if (!isTauri()) {
    window.open(url, '_blank', 'noopener,noreferrer');
    return;
  }
  try {
    await invoke('open_login_window', { url });
  } catch {
    await openUrl(url);
  }
}

async function hydrateProvider(): Promise<void> {
  try {
    const provider = await invoke<ProviderState | null>('load_ai_provider');
    if (provider) {
      state.provider = { ...state.provider, ...provider };
      persist();
      render();
    }
  } catch {
    // Browser preview has no secure Tauri storage; the form still works without it.
  }
}

render();
void hydrateProvider();
