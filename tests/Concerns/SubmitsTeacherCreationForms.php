<?php

namespace Tests\Concerns;

trait SubmitsTeacherCreationForms
{
    /** Source business-flow tests submit a real creation form, retaining Nisbiya's replay protection. */
    protected function postTeacherCreation(string $uri, array $data = [], array $headers = [])
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $kind = $path === '/teacher/assignments' ? 'assignments' : 'exams';
        $token = $this->get(route('teacher.'.$kind.'.create'))->assertOk()->viewData('creationToken');

        return $this->post($uri, $data + ['creation_token' => $token], $headers);
    }
}
