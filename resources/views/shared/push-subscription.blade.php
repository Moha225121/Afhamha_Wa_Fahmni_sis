<section class="list-section" data-push-panel>
    <button type="button" class="secondary-action" data-push-enable>تفعيل إشعارات الهاتف والمتصفح</button>
    <button type="button" class="secondary-action" data-push-disable hidden>إيقاف إشعارات هذا الجهاز</button>
    <p class="muted-line" data-push-status role="status" aria-live="polite">
        @if(app(\App\Services\FcmConfiguration::class)->webEnabled() || config('services.web_push.public_key'))
            فعّل الإشعارات على هذا الجهاز لتصلك التنبيهات حتى عند إغلاق الموقع.
        @else
            الإشعارات الفورية غير متاحة حاليًا. يمكنك متابعة الإشعارات داخل الموقع.
        @endif
    </p>
</section>
@once('school-push-activation-status')
    <script src="{{ asset('js/push-activation-status.js') }}?v=20261021-1" defer></script>
@endonce
