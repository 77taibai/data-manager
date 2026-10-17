<?php
declare(strict_types=1);

namespace Dlm\Support;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): void
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            Response::html($content);
            return;
        }
        $layoutData = array_merge($data, ['content' => $content]);
        Response::html(self::partial($layout, $layoutData));
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = DLM_VIEW . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('视图不存在: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
