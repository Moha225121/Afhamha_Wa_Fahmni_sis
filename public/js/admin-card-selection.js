(() => {
    const cards = [...document.querySelectorAll('.preview-pages .card-position')];
    const count = document.getElementById('selection-count');
    const printButton = document.getElementById('print-selected');
    const printPages = document.getElementById('print-pages');

    function selectedCards() {
        return cards.filter((card) => card.querySelector('.card-checkbox').checked);
    }

    function updateCount() {
        const selected = selectedCards().length;
        count.textContent = `تم تحديد ${selected} من ${cards.length} بطاقة`;
        printButton.disabled = selected === 0;
    }

    function buildPrintPages() {
        printPages.replaceChildren();
        selectedCards().forEach((card, index) => {
            if (index % 8 === 0) {
                const page = document.createElement('div');
                page.className = 'page';
                printPages.append(page);
            }
            const copy = card.cloneNode(true);
            copy.querySelector('.card-selection')?.remove();
            copy.style.left = `${8 + ((index % 2) * 101)}mm`;
            copy.style.top = `${8 + (Math.floor((index % 8) / 2) * 68)}mm`;
            printPages.lastElementChild.append(copy);
        });
    }

    cards.forEach((card) => card.querySelector('.card-checkbox').addEventListener('change', updateCount));
    document.getElementById('select-all').addEventListener('click', () => {
        cards.forEach((card) => { card.querySelector('.card-checkbox').checked = true; });
        updateCount();
    });
    document.getElementById('select-none').addEventListener('click', () => {
        cards.forEach((card) => { card.querySelector('.card-checkbox').checked = false; });
        updateCount();
    });
    printButton.addEventListener('click', () => {
        if (selectedCards().length === 0) return;
        buildPrintPages();
        document.body.classList.add('printing');
        // Make the selected pages part of the layout before Chrome snapshots
        // the document for Print to PDF.
        void printPages.offsetHeight;
        window.print();
    });
    window.addEventListener('beforeprint', () => {
        if (!document.body.classList.contains('printing')) {
            buildPrintPages();
            document.body.classList.add('printing');
        }
    });
    window.addEventListener('afterprint', () => {
        document.body.classList.remove('printing');
    });
    updateCount();
})();
