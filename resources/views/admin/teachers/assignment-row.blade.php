<div class="form-grid teacher-assignment-row">
    <label>الصف والشعبة
        <select class="teacher-assignment-classroom" name="assignments[{{ $index }}][classroom_id]">
            <option value="">اختر الصف</option>
            @foreach($classroomOptions as $option)
                <option value="{{ $option['id'] }}" @selected((string) data_get($assignment, 'classroom_id') === $option['id'])>{{ $option['label'] }}</option>
            @endforeach
        </select>
        <small class="teacher-assignment-group-hint"></small>
        @error("assignments.$index.classroom_id")<small class="form-error">{{ $message }}</small>@enderror
    </label>
    <label>المادة
        <select class="teacher-assignment-subject" name="assignments[{{ $index }}][subject_id]">
            <option value="">اختر المادة</option>
            @foreach($subjects as $subject)
                <option value="{{ $subject->id }}" @selected((string) data_get($assignment, 'subject_id') === (string) $subject->id)>{{ $subject->name }} - {{ $subject->code }}</option>
            @endforeach
        </select>
        @error("assignments.$index.subject_id")<small class="form-error">{{ $message }}</small>@enderror
    </label>
    <button class="btn secondary remove-teacher-assignment" type="button">حذف الإسناد</button>
</div>
