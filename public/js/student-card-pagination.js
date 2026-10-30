(() => {
    function paginate(items, pageSize = 10) {
        if (!Number.isInteger(pageSize) || pageSize < 1) {
            throw new RangeError('The page size must be a positive integer.');
        }

        const pages = [];
        for (let index = 0; index < items.length; index += pageSize) {
            pages.push(items.slice(index, index + pageSize));
        }

        return pages;
    }

    globalThis.StudentCardPagination = {
        paginate,
        pageCount: (count, pageSize = 10) => Math.ceil(count / pageSize),
    };
})();
