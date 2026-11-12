@extends('admin.layout')
@section('title','المعلمون')
@section('actions')<a class="btn primary" href="{{ route('admin.teachers.create') }}">إضافة معلم</a>@endsection
@section('content')
@include('admin.partials.account-filters', ['indexRoute' => 'admin.teachers.index'])
<div class="table-wrap"><table><thead><tr><th>الاسم</th><th>التخصص</th><th>المواد</th><th>الصفوف</th><th>الهاتف</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
@forelse($teachers as $t)
<tr><td>{{ $t->user->name }}</td><td>{{ $t->specialization ?: '—' }}</td><td>{{ $t->subjects->pluck('name')->unique()->join('، ') ?: '—' }}</td><td>{{ $t->classrooms->pluck('name')->unique()->join('، ') ?: '—' }}</td><td>{{ $t->user->phone ?: '—' }}</td><td>{{ $t->status }}</td><td><a href="{{ route('admin.teachers.edit',$t) }}">تعديل</a> <form method="post" action="{{ route('admin.module.toggle',['module'=>'teachers','id'=>$t->id]) }}" style="display:inline">@csrf @method('patch')<input type="hidden" name="status" value="{{ $t->status === 'active' ? 'inactive' : 'active' }}"><button class="btn secondary">{{ $t->status === 'active' ? 'إيقاف الحساب' : 'تفعيل الحساب' }}</button></form> <form method="post" action="{{ route('admin.teachers.destroy',$t) }}" style="display:inline">@csrf @method('delete')<button class="btn danger" type="submit" data-confirm="حذف المعلم الخالي من الارتباطات من القائمة وإيقاف دخوله؟ سيبقى السجل المؤرشف محفوظًا.">حذف</button></form></td></tr>
@empty<tr><td colspan="7">لا يوجد معلمون حاليًا.</td></tr>@endforelse
</tbody></table></div>{{ $teachers->links() }}
@endsection
