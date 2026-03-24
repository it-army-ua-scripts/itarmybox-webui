(function () {
    const messages = document.querySelectorAll('.form-message');
    if (!messages.length) {
        return;
    }

    const dismissAfterMs = 4500;
    const fadeDurationMs = 450;

    window.setTimeout(() => {
        messages.forEach((message) => {
            message.classList.add('is-fading');
            window.setTimeout(() => {
                message.remove();
            }, fadeDurationMs);
        });
    }, dismissAfterMs);
})();
