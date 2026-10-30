(function () {
    function applyLabels() {
        document.querySelectorAll('.table-wrap > table, .portal-table-scroll > table').forEach((table) => {
            const headerRow = table.tHead?.rows?.[table.tHead.rows.length - 1];
            if (!headerRow) return;

            const labels = Array.from(headerRow.cells).map((cell) => cell.textContent.trim());
            Array.from(table.tBodies).forEach((body) => {
                Array.from(body.rows).forEach((row) => {
                    Array.from(row.cells).forEach((cell, index) => {
                        if (!cell.hasAttribute('colspan') && labels[index]) {
                            cell.dataset.label = labels[index];
                        }
                    });
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyLabels);
    } else {
        applyLabels();
    }
})();
