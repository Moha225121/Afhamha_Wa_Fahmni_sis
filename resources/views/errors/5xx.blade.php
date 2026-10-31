@include('errors.school', [
    'statusCode' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500,
    'heading' => 'حدث خطأ في النظام',
    'message' => 'تعذر إكمال الطلب الآن. يرجى المحاولة مرة أخرى لاحقًا.',
])