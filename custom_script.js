(() => {
    // Initialize a global array to store console messages.
    window.__consoleMessages = [];

    // Save the original console methods.
    const origError = console.error;
    const origWarn = console.warn;
    const origLog = console.log;

    // Override console.error to capture error messages.
    console.error = function(...args) {
        window.__consoleMessages.push({ type: 'error', text: args.join(' ') });
        return origError.apply(console, args);
    };

    // Override console.warn to capture warning messages.
    console.warn = function(...args) {
        window.__consoleMessages.push({ type: 'warning', text: args.join(' ') });
        return origWarn.apply(console, args);
    };

    // Override console.log to capture log messages.
    console.log = function(...args) {
        window.__consoleMessages.push({ type: 'log', text: args.join(' ') });
        return origLog.apply(console, args);
    };
})();