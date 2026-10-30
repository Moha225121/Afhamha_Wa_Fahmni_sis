import test from 'node:test';
import assert from 'node:assert/strict';
import '../../public/js/student-card-pagination.js';

const pagination = globalThis.StudentCardPagination;

test('selected card counts create only the required ten-card A4 pages', () => {
    const expected = new Map([[1, 1], [4, 1], [8, 1], [10, 1], [11, 2], [20, 2], [25, 3]]);

    for (const [count, pages] of expected) {
        assert.equal(pagination.pageCount(count), pages);
        const result = pagination.paginate(Array.from({ length: count }, (_, index) => index + 1));
        assert.equal(result.length, pages);
        assert.equal(result.flat().length, count);
        assert.equal(new Set(result.flat()).size, count);
        assert.deepEqual(result.flat(), Array.from({ length: count }, (_, index) => index + 1));
        assert.ok(result.slice(0, -1).every((page) => page.length === 10));
        assert.ok(result.at(-1).length >= 1 && result.at(-1).length <= 10);
    }
});

test('empty selections yield zero pages and invalid page sizes are rejected', () => {
    assert.deepEqual(pagination.paginate([]), []);
    assert.equal(pagination.pageCount(0), 0);
    assert.throws(() => pagination.paginate([1], 0), RangeError);
});
