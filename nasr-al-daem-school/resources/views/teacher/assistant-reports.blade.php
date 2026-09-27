@extends('teacher.layout')
@section('title','تقارير المساعد')
@section('subtitle','ملخصات سريعة ومؤشرات الصفوف')
@section('content')
<div class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الصف</th>
                    <th>عدد الطلاب</th>
                    <th>الحضور</th>
                    <th>الاختبارات</th>
                    <th>المتوسط</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>الصف الأول</td>
                    <td>25</td>
                    <td>92%</td>
                    <td>3</td>
                    <td>86%</td>
                </tr>
                <tr>
                    <td>الصف الثاني</td>
                    <td>28</td>
                    <td>89%</td>
                    <td>4</td>
                    <td>82%</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
