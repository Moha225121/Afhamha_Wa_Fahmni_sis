import assert from 'node:assert/strict';
import test from 'node:test';
import {ratingFor, reportPercentage, normalizeReportSearch, matchesReportSearch, eligibleReportSource, applyEntryModes, initTeacherReports} from '../../public/js/teacher-reports.js';

const thresholds = {excellent: 85, very_good: 75, good: 65};
const entry = overrides => ({automatic_score: 80, automatic_rating: 'very_good', score_mode: 'auto', score: 80, rating_mode: 'auto', rating: 'very_good', behaviour: null, activity: '', notes: '', ...overrides});
const source = overrides => ({key: 'exam:1', subject_id: 3, assessment_type: 'weekly', assessment_date: '2026-10-28', ...overrides});
const config = overrides => ({report_type: 'weekly', subject_ids: [3], categories: null, from: '2026-10-25', to: '2026-10-31', ...overrides});

test('four report ratings use the visible inclusive thresholds and retain a real zero', () => {
    for (const [percent, expected] of [[0, 'attention'], [64.99, 'attention'], [65, 'good'], [74.99, 'good'], [75, 'very_good'], [84.99, 'very_good'], [85, 'excellent'], [100, 'excellent'], ['85', 'excellent']]) {
        assert.equal(ratingFor(percent, thresholds), expected);
    }
});

test('unrecorded and invalid percentages do not become a failing grade', () => {
    for (const value of [null, undefined, '', ' ', NaN, Infinity, -1, 100.01, true, false, [], {}]) assert.equal(ratingFor(value, thresholds), null);
});

test('custom thresholds are supported while invalid or overlapping cutoffs cannot classify a grade', () => {
    assert.equal(ratingFor(90, {excellent: 90, very_good: 80, good: 70}), 'excellent');
    assert.equal(ratingFor(85, {excellent: 90, very_good: 80, good: 70}), 'very_good');
    for (const limits of [{excellent: 60, very_good: 75, good: 65}, {excellent: 85, very_good: 75, good: 75}, {excellent: 101, very_good: 75, good: 65}, {excellent: 85, very_good: 75, good: -1}]) assert.equal(ratingFor(80, limits), null);
});

test('mean percentages and total-points aggregation remain different for unequal assessment maxima', () => {
    const marks = [{score: 8, maximum: 10}, {score: 0, maximum: 20}];
    assert.equal(reportPercentage(marks, 'mean'), 40);
    assert.equal(reportPercentage(marks, 'points'), 26.67);
    assert.equal(reportPercentage([{score: 0, maximum: 20}], 'mean'), 0);
    assert.equal(reportPercentage([{score: '16.5', maximum: '20'}], 'mean'), 82.5);
});

test('invalid evidence cannot add an assessment or silently contribute a zero to the report', () => {
    const bad = [null, {score: null, maximum: 20}, {score: '', maximum: 20}, {score: true, maximum: 20}, {score: -1, maximum: 20}, {score: 21, maximum: 20}, {score: 1, maximum: 0}, {score: Infinity, maximum: 20}, {score: [], maximum: 20}, {score: 1, maximum: {}}];
    assert.equal(reportPercentage([], 'mean'), null);
    assert.equal(reportPercentage(bad, 'mean'), null);
    assert.equal(reportPercentage([...bad, {score: 8, maximum: 10}], 'mean'), 80);
});

test('automatic entry values come from saved evidence rather than a stale displayed manual number', () => {
    const result = applyEntryModes(entry({score: 99, rating: 'excellent'}), thresholds);
    assert.equal(result.score, 80);
    assert.equal(result.rating, 'very_good');
});

test('a manual grade drives the automatic rating while an explicit manual rating is retained', () => {
    const automaticRating = applyEntryModes(entry({score_mode: 'manual', score: 92}), thresholds);
    assert.equal(automaticRating.score, 92);
    assert.equal(automaticRating.rating, 'excellent');
    const manualRating = applyEntryModes(entry({score_mode: 'manual', score: 92, rating_mode: 'manual', rating: 'good'}), thresholds);
    assert.equal(manualRating.rating, 'good');
});

test('a deliberate blank manual grade stays blank and is not restored or classified as zero', () => {
    const blank = applyEntryModes(entry({score_mode: 'manual', score: null}), thresholds);
    assert.equal(blank.score, null);
    assert.equal(blank.rating, null);
    const zero = applyEntryModes(entry({score_mode: 'manual', score: 0}), thresholds);
    assert.equal(zero.score, 0);
    assert.equal(zero.rating, 'attention');
    const blankRating = applyEntryModes(entry({rating_mode: 'manual', rating: null}), thresholds);
    assert.equal(blankRating.rating, null);
});

test('academic calculations never fill behaviour, activity, or notes automatically', () => {
    const result = applyEntryModes(entry({behaviour: 'calm', activity: 'شارك في الحوار', notes: 'ملاحظة المعلم'}), thresholds);
    assert.equal(result.behaviour, 'calm');
    assert.equal(result.activity, 'شارك في الحوار');
    assert.equal(result.notes, 'ملاحظة المعلم');
    const untouched = applyEntryModes(entry(), thresholds);
    assert.equal(untouched.behaviour, null);
    assert.equal(untouched.activity, '');
    assert.equal(untouched.notes, '');
});

test('Arabic student search handles letter variants, diacritics, tatweel, case, partial names and reordered tokens', () => {
    assert.equal(normalizeReportSearch('أَحْمَـد علي'), normalizeReportSearch('احمد علي'));
    assert.equal(normalizeReportSearch('نُورَة مصطفى'), normalizeReportSearch('نوره مصطفي'));
    assert.equal(matchesReportSearch('أحمد محمد الجهاني', 'محمد احم'), true);
    assert.equal(matchesReportSearch('Sarah Test Student', 'STUD sar'), true);
    assert.equal(matchesReportSearch('أحمد محمد الجهاني', 'أحمد سارة'), false);
    assert.equal(matchesReportSearch('أي طالب', ''), true);
});

test('source filtering requires an actual selected subject and the chosen assessment category', () => {
    assert.equal(eligibleReportSource(source(), config()), true);
    assert.equal(eligibleReportSource(source({subject_id: null}), config()), false);
    assert.equal(eligibleReportSource(source({subject_id: 9}), config()), false);
    assert.equal(eligibleReportSource(source({assessment_type: 'monthly'}), config()), false);
    assert.equal(eligibleReportSource(source({assessment_type: null}), config()), false);
    assert.equal(eligibleReportSource(source({assessment_type: 'monthly'}), config({categories: ['weekly', 'monthly']})), true);
});

test('school calendar boundaries are inclusive and undated grades are not guessed into a weekly or monthly period', () => {
    assert.equal(eligibleReportSource(source({assessment_date: '2026-10-25'}), config()), true);
    assert.equal(eligibleReportSource(source({assessment_date: '2026-10-31'}), config()), true);
    assert.equal(eligibleReportSource(source({assessment_date: '2026-10-24'}), config()), false);
    assert.equal(eligibleReportSource(source({assessment_date: '2026-11-01'}), config()), false);
    assert.equal(eligibleReportSource(source({assessment_date: null}), config()), false);
});

test('overall reports can include an undated known-subject grade and custom empty categories mean no category filter', () => {
    assert.equal(eligibleReportSource(source({assessment_type: null, assessment_date: null}), config({report_type: 'overall', from: null, to: null})), true);
    assert.equal(eligibleReportSource(source({assessment_type: 'final'}), config({categories: []})), true);
    assert.equal(eligibleReportSource(source({assessment_type: 'final'}), config({report_type: 'midterm'})), false);
    assert.equal(eligibleReportSource(source({assessment_type: 'final'}), config({report_type: 'final'})), true);
});

test('report initialization is inert on pages without a report editor', () => {
    assert.equal(initTeacherReports({querySelector: () => null}), null);
});
