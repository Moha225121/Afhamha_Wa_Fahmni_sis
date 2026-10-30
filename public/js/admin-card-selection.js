(() => {
    const cards = [...document.querySelectorAll('.preview-pages .card-position')];
    const count = document.getElementById('selection-count');
    const printButton = document.getElementById('print-selected');
    const downloadButton = document.getElementById('download-pdf-selected');
    const printPages = document.getElementById('print-pages');
    const downloadForm = document.getElementById('pdf-download-form');
    const pagination = globalThis.StudentCardPagination;

    if (!count || !printButton || !downloadButton || !printPages || !downloadForm || !pagination) {
        return;
    }

    function selectedCards() {
        return cards.filter((card) => card.querySelector('.card-checkbox').checked);
    }

    function updateCount() {
        const selected = selectedCards().length;
        count.textContent = `تم تحديد ${selected} من ${cards.length} بطاقة`;
        printButton.disabled = selected === 0;
        downloadButton.disabled = selected === 0;
    }

    function buildPrintPages() {
        printPages.replaceChildren();
        const pages = pagination.paginate(selectedCards());

        pages.forEach((cardsOnPage) => {
            const page = document.createElement('div');
            const grid = document.createElement('div');
            page.className = 'page printed-page';
            grid.className = 'print-page-grid';

            cardsOnPage.forEach((card) => {
                const copy = card.cloneNode(true);
                copy.querySelector('.card-selection')?.remove();
                copy.removeAttribute('style');
                grid.append(copy);
            });

            page.append(grid);
            printPages.append(page);
        });
    }

    cards.forEach((card) => card.querySelector('.card-checkbox').addEventListener('change', updateCount));
    document.getElementById('select-all')?.addEventListener('click', () => {
        cards.forEach((card) => { card.querySelector('.card-checkbox').checked = true; });
        updateCount();
    });
    document.getElementById('select-none')?.addEventListener('click', () => {
        cards.forEach((card) => { card.querySelector('.card-checkbox').checked = false; });
        updateCount();
    });
    printButton.addEventListener('click', () => {
        if (selectedCards().length === 0) return;
        buildPrintPages();
        window.print();
    });
    downloadButton.addEventListener('click', () => {
        const selected = selectedCards();
        if (selected.length === 0) return;

        downloadForm.querySelectorAll('[data-selected-student]').forEach((input) => input.remove());
        selected.forEach((card) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'student_ids[]';
            input.value = card.dataset.studentId;
            input.dataset.selectedStudent = 'true';
            downloadForm.append(input);
        });
        downloadForm.requestSubmit();
    });
    window.addEventListener('beforeprint', buildPrintPages);
    updateCount();
})();
