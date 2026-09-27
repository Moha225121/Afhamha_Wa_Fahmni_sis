@extends('admin.layout')
@section('title','معاينة تغييرات الحسابات')
@section('content')
<div class="panel"><h2>{{ count($plan['rows']) }} حسابًا ستتم معالجتها</h2><p>تُحفظ معرفات الطلاب والمعلمين والعلاقات الحالية. راجع الأرقام والبريد قبل اعتماد العملية.</p><p>المدرسة: {{ $settings['school_name'] }} — <b dir="ltr">{{ $settings['school_english_name'] }}</b></p></div>
<div class="table-wrap"><table><thead><tr><th>الاسم</th><th>الرقم السابق</th><th>الرقم الجديد</th><th>البريد السابق</th><th>البريد الجديد</th></tr></thead><tbody>
@forelse($plan['rows'] as $row)<tr><td>{{ $row['name'] }}</td><td dir="ltr">{{ $row['old_number'] }}</td><td dir="ltr">{{ $row['number'] }}</td><td dir="ltr">{{ $row['old_email'] }}</td><td dir="ltr">{{ $row['email'] }}</td></tr>@empty<tr><td colspan="5">حفظ إعدادات الحسابات الجديدة فقط؛ لن تتغير الحسابات الحالية.</td></tr>@endforelse
</tbody></table></div>
<details class="panel"><summary>الإعدادات التي ستُحفظ</summary>@foreach($settings as $key=>$value)<p><code>{{ $key }}</code>: <b dir="ltr">{{ is_bool($value)?($value?'مفعّل':'معطّل'):$value }}</b></p>@endforeach</details>
<form method="post" action="{{ route('admin.accounts.update') }}">@csrf @method('put')<input type="hidden" name="revision" value="{{ $plan['revision'] }}"><button class="btn primary">اعتماد وحفظ التغييرات</button> <a class="btn secondary" href="{{ route('admin.accounts.index') }}">العودة للتعديل</a></form>
@endsection
