<?php
$e = static fn (mixed $value): string => \App\Support\View::escape((string) $value);
$lines = static fn (string $text): string => implode('<br>', array_map(
    static function (string $line) use ($e): string {
        $line = str_replace("\t", '    ', $line);
        preg_match('/^ */', $line, $indent);
        $length = strlen($indent[0]);
        return str_repeat('&nbsp;', $length) . $e(substr($line, $length));
    },
    preg_split('/\R/u', $text) ?: [$text]
));
?>
<h1 class="pdf-title"><?= $e($document['title']) ?></h1>
<?php if ($document['selective']): ?><p class="pdf-selection"><?= $e($document['label']) ?></p><?php endif; ?>
<?php $division = null; ?>
<?php foreach ($document['scenes'] as $scene): ?>
    <!-- scriptises-pdf-part -->
    <?php
    $context = implode(' / ', array_filter([$scene['episode'], $scene['act']]));
    $showDivision = $context !== '' && ($document['selective'] || $context !== $division);
    $division = $context;
    $cue = false;
    $speaker = null;
    ?>
    <?php if ($showDivision): ?><p class="pdf-context"><?= $e($context) ?></p><?php endif; ?>
    <h2 class="pdf-heading"><?= $e($scene['heading'] ?: 'Untitled scene') ?></h2>
    <?php foreach ($scene['blocks'] as $block): ?>
        <!-- scriptises-pdf-part -->
        <?php
        $type = $block['block_type'];
        $style = $block['metadata']['fountain'] ?? '';
        $class = match ($type) {
            'character' => 'pdf-cue',
            'dialogue' => 'pdf-dialogue',
            'parenthetical' => 'pdf-parenthetical',
            'transition' => 'pdf-transition',
            'note' => 'pdf-note',
            default => $style === 'centered' ? 'pdf-centered' : 'pdf-action',
        };
        if ($type === 'dialogue') {
            $name = $block['speaker_name'] ?: 'Unassigned speaker';
            if (!$cue && $speaker !== $name) {
                echo '<p class="pdf-cue">' . $e(mb_strtoupper($name)) . '</p>';
            }
            $speaker = $name;
            $cue = false;
        } elseif ($type === 'character') {
            $cue = true;
            $speaker = null;
        } elseif (!in_array($type, ['parenthetical', 'note'], true)) {
            $cue = false;
            $speaker = null;
        }
        ?>
        <p class="<?= $class ?>"><?= $block['content'] === '' ? '&nbsp;' : $lines($block['content']) ?></p>
    <?php endforeach; ?>
<?php endforeach; ?>
