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
<input type="hidden" id="avatar-data" name="avatar_data">
<div class="form-actions"><button class="btn primary" type="submit">حفظ</button><a class="btn secondary" href="{{ route('teacher.dashboard') }}">إلغاء</a></div>
</form>

<!-- Cropper Modal -->
<div id="avatar-cropper-modal" class="avatar-cropper-modal" hidden>
	<div class="avatar-cropper-overlay"></div>
	<div class="avatar-cropper-dialog">
		<div class="avatar-cropper-header">
			<h3>قص وتموضع الصورة</h3>
			<button type="button" class="avatar-cropper-close" id="cropper-close">&times;</button>
		</div>
		<div class="avatar-cropper-body">
			<div class="avatar-cropper-container">
				<img id="avatar-cropper-image" src="">
			</div>
		</div>
		<div class="avatar-cropper-footer">
			<button type="button" class="btn secondary" id="cropper-cancel">إلغاء</button>
			<button type="button" class="btn primary" id="cropper-confirm">تأكيد</button>
		</div>
	</div>
</div>

@section('scripts')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
(function () {
	const input = document.getElementById('teacher-avatar-input');
	const preview = document.getElementById('teacher-avatar-preview');
	const modal = document.getElementById('avatar-cropper-modal');
	const cropperImage = document.getElementById('avatar-cropper-image');
	const avatarDataInput = document.getElementById('avatar-data');
	const form = preview.closest('form');
	let cropper = null;
	let previewUrl = null;

	input?.addEventListener('change', function () {
		const file = this.files?.[0];
		if (!file || !file.type.startsWith('image/')) return;

		if (previewUrl) URL.revokeObjectURL(previewUrl);
		previewUrl = URL.createObjectURL(file);
		cropperImage.src = previewUrl;
		modal.hidden = false;

		// Initialize cropper on next tick to ensure image is loaded
		setTimeout(() => {
			if (cropper) cropper.destroy();
			cropper = new Cropper(cropperImage, {
				aspectRatio: 1,
				viewMode: 1,
				autoCropArea: 1,
				responsive: true,
				restore: true,
				guides: true,
				center: true,
				highlight: true,
				cropBoxMovable: true,
				cropBoxResizable: true,
				toggleDragModeOnDblclick: true,
			});
		}, 0);
	});

	document.getElementById('cropper-confirm')?.addEventListener('click', function () {
		if (!cropper) return;

		const canvas = cropper.getCroppedCanvas({
			maxWidth: 400,
			maxHeight: 400,
			fillColor: '#fff',
			imageSmoothingEnabled: true,
			imageSmoothingQuality: 'high',
		});

		// Convert to base64 and store
		avatarDataInput.value = canvas.toDataURL('image/png');

		// Update preview
		preview.innerHTML = '';
		const img = document.createElement('img');
		img.src = canvas.toDataURL('image/png');
		img.alt = 'معاينة صورة الملف الشخصي';
		preview.appendChild(img);

		// Close modal
		modal.hidden = true;
		cropper.destroy();
		cropper = null;
		input.value = '';
	});

	document.getElementById('cropper-cancel')?.addEventListener('click', function () {
		modal.hidden = true;
		if (cropper) cropper.destroy();
		cropper = null;
		input.value = '';
		if (previewUrl) URL.revokeObjectURL(previewUrl);
	});

	document.getElementById('cropper-close')?.addEventListener('click', function () {
		modal.hidden = true;
		if (cropper) cropper.destroy();
		cropper = null;
		input.value = '';
		if (previewUrl) URL.revokeObjectURL(previewUrl);
	});

	// Close modal on overlay click
	document.querySelector('.avatar-cropper-overlay')?.addEventListener('click', function () {
		modal.hidden = true;
		if (cropper) cropper.destroy();
		cropper = null;
		input.value = '';
		if (previewUrl) URL.revokeObjectURL(previewUrl);
	});
}());
</script>
@endsection
@endsection
