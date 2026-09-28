function bootConfig() {
	return window.agenticAdminPage || { page: 'tools', tab: '' };
}

// Screens with a Basic/Advanced content split, and thus a ScreenModeToggle
// in AdminPage's top-right actions slot. Must match the screens the
// set_screen_mode REST action recognizes (class-admin-pages-rest.php).

export { bootConfig };
