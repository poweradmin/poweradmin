document.addEventListener('DOMContentLoaded', function() {
    var textareas = document.querySelectorAll('textarea');

    textareas.forEach(function(textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });

        if (textarea.value !== '') {
            textarea.dispatchEvent(new Event('input'));
        }
    });
});
