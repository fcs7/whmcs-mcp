(() => {
    'use strict';

    if (window !== window.top || Object.prototype.hasOwnProperty.call(window, 'NTWebMCP')) {
        return;
    }

    let supported = false;
    try {
        supported = window.isSecureContext === true
            && 'modelContext' in document
            && typeof document.modelContext?.registerTool === 'function';
    } catch {
        // A disabled policy or unavailable experimental API is a normal outcome.
    }

    // Diagnostic state only. "ready" means API support, not agent availability.
    // No registrations, discovery, network requests or account data in this phase.
    Object.defineProperty(window, 'NTWebMCP', {
        value: Object.freeze({
            version: '1.0.0',
            status: supported ? 'ready' : 'unsupported',
            toolCount: 0,
        }),
        writable: false,
        configurable: false,
    });
})();
