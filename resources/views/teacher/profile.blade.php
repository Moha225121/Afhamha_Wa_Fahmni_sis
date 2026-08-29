@extends('teacher.layout') @section('title','الملف الشخصي') @section('content')
<form class="form-card teacher-profile-form" method="post" enctype="multipart/form-data" action="{{ route('teacher.profile.update') }}">
@csrf @method('put')
<div class="teacher-profile-hero"><div class="teacher-profile-avatar" id="teacher-avatar-preview">@if(auth()->user()->avatar_path)<img src="{{ route('teacher.profile.avatar', ['v' => auth()->user()->updated_at?->timestamp]) }}" alt="صورة {{ auth()->user()->name }}">@else<span>{{ mb_substr(auth()->user()->name, 0, 1) }}</span>@endif</div><div><span class="eyebrow">الملف الشخصي</span><h2>{{ auth()->user()->name }}</h2><p>أضف بياناتك وصورتك لتظهر في بوابة المعلم.</p></div><label class="btn secondary teacher-avatar-upload">تغيير الصورة<input type="file" name="avatar" id="teacher-avatar-input" accept="image/jpeg,image/png,image/webp"></label></div>
<div class="form-grid">
<label><span class="label-head">الاسم الكامل</span><input name="name" required value="{{ old('name', auth()->user()->name) }}"></label>
<label><span class="label-head">رقم الهاتف</span><input name="phone" value="{{ old('phone', auth()->user()->phone) }}"></label>
<label class="wide">البريد الإلكتروني<input value="{{ auth()->user()->email }}" disabled></label>
<label class="wide"><span class="label-head">التخصص</span><input name="specialization" value="{{ old('specialization', $teacher->specialization) }}"></label>
</div>
<div class="form-actions"><button class="btn primary" type="submit">حفظ</button><a class="btn secondary" href="{{ route('teacher.dashboard') }}">إلغاء</a></div>
</form>
@section('scripts')
<script>
(function () {
	const input = document.getElementById('teacher-avatar-input');
	const preview = document.getElementById('teacher-avatar-preview');
	let previewUrl = null;

	input?.addEventListener('change', function () {
		const file = this.files?.[0];
		if (!file || !file.type.startsWith('image/')) return;
		if (previewUrl) URL.revokeObjectURL(previewUrl);
		previewUrl = URL.createObjectURL(file);
		preview.innerHTML = '';
		const image = document.createElement('img');
		image.src = previewUrl;
		image.alt = 'معاينة صورة الملف الشخصي';
		preview.appendChild(image);
	});
}());
</script>
@endsection
@endsection
