<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ManagementService;
use App\Services\ScreenplayService;
use App\Support\Flash;
use App\Support\Http;
use App\Support\View;

final class ManagementController extends BaseController
{
    public function __construct(AuthService $auth, private readonly ScreenplayService $scripts, private readonly ManagementService $management)
    {
        parent::__construct($auth);
    }

    public function find(string $uuid, bool $characterView = false): void
    {
        $user = $this->user();
        $data = $this->scripts->catalog((int) $user['id'], $uuid);
        $projectId = (int) $data['project']['id'];
        $filters = self::filters($_GET);
        if ($characterView && $filters['character'] === '' && $data['characters']) {
            $filters['character'] = $data['characters'][0]['uuid'];
        }
        if ($characterView && !isset($_GET['relation'])) {
            $filters['relation'] = 'dialogue';
        }
        $profile = $characterView && $filters['character'] !== '' ? $this->management->character($projectId, $filters['character']) : null;
        $results = $characterView && !$profile ? [] : $this->management->search((int) $user['id'], $uuid, $filters);
        View::render('finder', $data + [
            'pageTitle' => $characterView ? 'Character dialogue' : 'Search screenplay',
            'user' => $user, 'filters' => $filters, 'profile' => $profile, 'characterView' => $characterView,
            'plots' => $this->management->plots($projectId), 'results' => array_slice($results, 0, 30), 'hasNext' => count($results) > 30,
        ]);
    }

    public function saveCharacter(string $uuid, string $character): void
    {
        $user = $this->user();
        $this->csrf();
        $this->management->saveCharacter((int) $user['id'], $uuid, $character, $this->requestText('name'), $this->requestText('description'), $this->requestText('aliases'));
        Flash::set('success', 'Character profile saved.');
        Http::redirect("/projects/$uuid/characters?character=$character");
    }

    public function linkCharacter(string $uuid, string $scene): void
    {
        $user = $this->user();
        $this->csrf();
        $this->management->linkCharacter((int) $user['id'], $uuid, $scene, $this->requestText('character'), $this->requestText('relation'), $this->requestText('notes'));
        Http::redirect("/projects/$uuid/scenes/$scene?characters=1#scene-characters");
    }

    public function plots(string $uuid): void
    {
        $user = $this->user();
        $data = $this->scripts->workspace((int) $user['id'], $uuid);
        $projectId = (int) $data['project']['id'];
        $selected = is_string($_GET['thread'] ?? null) ? $_GET['thread'] : '';
        $plots = $this->management->plots($projectId);
        $plot = $selected !== '' ? $this->management->plot($projectId, $selected) : null;
        $linked = $plot ? $this->management->search((int) $user['id'], $uuid, ['plot' => $selected, 'view' => 'scenes'], true) : [];
        View::render('plots', $data + ['pageTitle' => 'Plot threads', 'user' => $user, 'plots' => $plots, 'plot' => $plot, 'linked' => $linked]);
    }

    public function savePlot(string $uuid): void
    {
        $user = $this->user();
        $this->csrf();
        $links = [];
        foreach (ManagementService::PLOT_LINKS as $kind) {
            $links[$kind] = array_filter(is_array($_POST[$kind] ?? null) ? $_POST[$kind] : [], 'is_string');
        }
        $plot = $this->management->savePlot((int) $user['id'], $uuid, $this->requestText('plot'), $this->requestText('title'), $this->requestText('description'), $links);
        Flash::set('success', 'Plot thread saved.');
        Http::redirect("/projects/$uuid/plots?thread=$plot");
    }

    public function deletePlot(string $uuid, string $plot): void
    {
        $user = $this->user();
        $this->csrf();
        $this->management->deletePlot((int) $user['id'], $uuid, $plot);
        Flash::set('success', 'Plot thread deleted.');
        Http::redirect("/projects/$uuid/plots");
    }

    public function history(string $uuid, string $block): void
    {
        $user = $this->user();
        View::render('history', $this->scripts->blockHistory((int) $user['id'], $uuid, $block) + ['pageTitle' => 'Writing history', 'user' => $user]);
    }

    public function restoreBlock(string $uuid, string $block): void
    {
        $user = $this->user();
        $this->csrf();
        $this->scripts->restoreBlock((int) $user['id'], $uuid, $block, (int) $this->requestText('revision'), (int) $this->requestText('version'));
        Flash::set('success', 'Earlier text restored.');
        Http::redirect("/projects/$uuid/blocks/$block/history");
    }

    public static function filters(array $input): array
    {
        $filters = [];
        foreach (['q', 'location', 'view', 'type', 'episode', 'act', 'character', 'relation', 'plot', 'page'] as $key) {
            $filters[$key] = is_string($input[$key] ?? null) ? mb_substr(trim($input[$key]), 0, 255) : '';
        }
        $filters['page'] = max(1, min(100000, (int) $filters['page']));
        return $filters;
    }
}
