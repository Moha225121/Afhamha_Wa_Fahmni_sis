(() => {
    const input = document.getElementById('student-photo');
    const preview = document.getElementById('student-photo-preview');
    if (!input || !preview) return;

    const savedPhoto = preview.getAttribute('src');
    let previewUrl;
    input.addEventListener('change', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        const file = input.files[0];
        if (file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            previewUrl = URL.createObjectURL(file);
        }
        const source = previewUrl || savedPhoto;
        preview.hidden = !source;
        if (source) preview.src = source;
        else preview.removeAttribute('src');
    });
})();
