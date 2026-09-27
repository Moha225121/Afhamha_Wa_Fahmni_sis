<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccountPasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountPasswordController extends Controller
{
    public function update(Request $request, AccountPasswordService $passwords): RedirectResponse
    {
        $data = $request->validate(
            ['password' => ['required', 'string', 'min:7', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('كلمة المرور طويلة جدًا؛ استخدم رمزًا أقصر.');
                }
            }]],
            [
                'password.required' => 'أدخل كلمة المرور الافتراضية الجديدة.',
                'password.min' => 'يجب أن تتكون كلمة المرور من 7 أحرف على الأقل.',
                'password.max' => 'يجب ألا تتجاوز كلمة المرور 72 حرفًا.',
                'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            ],
        );

        $passwords->updateDefault($data['password']);

        return redirect()->route('admin.settings.index')
            ->with('success', 'تم تغيير كلمة المرور الافتراضية للحسابات الجديدة. كلمات مرور الحسابات الحالية لم تتغير.');
    }
}
