<form class="filters" method="get">
    <input name="q" value="{{ request('q') }}" placeholder="الاسم أو البريد أو الهاتف" aria-label="البحث بالاسم أو البريد أو الهاتف">
    <select name="status" aria-label="الحالة"><option value="">كل الحالات</option><option value="active" @selected(request('status') === 'active')>نشط</option><option value="inactive" @selected(request('status') === 'inactive')>موقوف</option></select>
    <button class="btn secondary">بحث</button><a class="btn secondary" href="{{ route($indexRoute) }}">إعادة الضبط</a>
</form>
