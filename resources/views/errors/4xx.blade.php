@include('errors.school', [
    'statusCode' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400,
    'heading' => 'تعذر إكمال الطلب',
    'message' => 'تحقق من الرابط أو صلاحية الوصول، ثم حاول مرة أخرى.',
])