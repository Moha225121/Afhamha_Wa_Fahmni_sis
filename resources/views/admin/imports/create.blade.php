@extends('admin.layout')
@section('title','استيراد '.$label.' من Excel')
@section('content')
<section class="form-card">
<h2>رفع ملف {{ $label }}</h2>
<p class="muted">يدعم النظام ملفات XLSX وCSV. الصف الأول يجب أن يحتوي على أسماء الأعمدة. ينشئ الاستيراد سجلات جديدة فقط، ويحافظ على السجلات المطابقة وحساباتها وكلمات مرورها دون تعديل. عند وجود تعارض لا يُحفظ أي جزء من الملف.</p>
<p class="muted">للصفوف: استخدم مرحلة إعدادية أو ثانوية وسنة دراسية موجودة، وحدد الشعبة عند تشابه أسماء الصفوف.</p>
<p><a class="btn secondary" href="{{ route('admin.imports.template', $type) }}">تنزيل قالب Excel لـ {{ $label }}</a></p>
<form method="post" action="{{ route('admin.imports.store',$type) }}" enctype="multipart/form-data">
@csrf
<label>ملف Excel<input type="file" name="file" accept=".xlsx,.csv" required></label>
<div class="form-actions"><button class="btn primary">رفع وإنشاء السجلات</button><a class="btn secondary" href="{{ route($type === 'stages' ? 'admin.classes.index' : 'admin.'.$type.'.index') }}">إلغاء</a></div>
</form>
</section>
@endsection
