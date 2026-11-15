<?php

namespace OGame\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Models\Alliance;
use OGame\Models\Highscore;

/**
 * Read-only public feed of the top players, for outside sites such as the
 * xLAx clan website. Returns names, alliance tags and points only: no
 * emails, account IDs or coordinates. Admin accounts and Legor are always
 * left out, whatever the in-game highscore setting says.
 */
class PublicHighscoreController extends Controller
{
    public const CACHE_KEY = 'public-highscores-top10';
    private const LIMIT = 10;

    /**
     * Top 10 players for each highscore category.
     *
     * @return JsonResponse
     */
    public function top(): JsonResponse
    {
        $payload = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function (): array {
            $categories = [];
            foreach (HighscoreTypeEnum::cases() as $type) {
                $categories[$type->name] = $this->topFor($type);
            }

            $updatedAt = Highscore::query()->max('updated_at');

            return [
                'game' => 'Iron Armada',
                'players' => $this->rankedPlayers()->count(),
                'updated_at' => $updatedAt !== null ? date(DATE_ATOM, (int) strtotime((string) $updatedAt)) : null,
                'categories' => $categories,
            ];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * @param HighscoreTypeEnum $type
     * @return array<int, array{rank: int, name: string, alliance: string|null, points: int, bot: bool}>
     */
    private function topFor(HighscoreTypeEnum $type): array
    {
        $rows = $this->rankedPlayers()
            ->with(['player.alliance'])
            ->orderByDesc($type->name)
            ->orderBy('player_id')
            ->limit(self::LIMIT)
            ->get();

        $list = [];
        $rank = 1;
        foreach ($rows as $row) {
            $player = $row->player;
            /** @var Alliance|null $alliance */
            $alliance = $player->alliance;
            $list[] = [
                'rank' => $rank++,
                'name' => (string) $player->username,
                'alliance' => $alliance?->alliance_tag,
                'points' => (int) $row->{$type->name},
                'bot' => (bool) $player->is_bot,
            ];
        }

        return $list;
    }

    /**
     * Highscore rows for real, ranked players: has a game state, is not an
     * admin and is not Legor.
     *
     * @return Builder<Highscore>
     */
    private function rankedPlayers(): Builder
    {
        return Highscore::query()
            ->whereHas('player.tech')
            ->whereHas('player', function (Builder $query): void {
                $query->where('username', '!=', 'Legor')
                    ->whereDoesntHave('roles', function (Builder $roleQuery): void {
                        $roleQuery->where('name', 'admin');
                    });
            });
    }
}
