<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQuestionsService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * @covers \App\Services\Pregao\PregaoQuestionsService
 */
class PregaoQuestionsServiceTest extends TestCase
{
    public function testMedianIsPrimaryStat(): void
    {
        // outlier 51h não deve puxar a mediana
        $secs = [1002, 1800, 3360, 5600, 5865, 7117, 31563, 184645];
        $med = PregaoQuestionsService::median($secs);
        $this->assertSame(5733, $med); // (5600+5865)/2
        $avg = (int) round(array_sum($secs) / count($secs));
        $this->assertGreaterThan($med * 3, $avg);
    }

    public function testCardStatusRules(): void
    {
        $this->assertSame('verde', PregaoQuestionsService::resolveCardStatus(95.0, []));
        $this->assertSame(
            'amarelo',
            PregaoQuestionsService::resolveCardStatus(80.0, [])
        );
        $this->assertSame(
            'amarelo',
            PregaoQuestionsService::resolveCardStatus(95.0, [['open_seconds' => 7201]])
        );
        $this->assertSame(
            'vermelho',
            PregaoQuestionsService::resolveCardStatus(60.0, [])
        );
        $this->assertSame(
            'vermelho',
            PregaoQuestionsService::resolveCardStatus(95.0, [['open_seconds' => 43201]])
        );
    }

    public function testFormatDurationHuman(): void
    {
        $this->assertSame('42min', PregaoQuestionsService::formatDurationHuman(42 * 60));
        $this->assertSame('8h21', PregaoQuestionsService::formatDurationHuman(8 * 3600 + 21 * 60));
        $this->assertSame('2h00', PregaoQuestionsService::formatDurationHuman(7200));
    }

    public function testMlQuestionLinkContemItemEQuestion(): void
    {
        $url = PregaoQuestionsService::mlQuestionLink('MLB6574414098', '12345');
        $this->assertStringContainsString('mercadolivre.com.br/perguntas', $url);
        $this->assertStringContainsString('question_id=12345', $url);
        $this->assertStringContainsString('MLB-6574414098', $url);
    }

    public function testAlertaPerguntaAbertaEmitidoUmaVezPorPergunta(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $info = $this->createMock(PDOStatement::class);
        $info->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('query')->willReturn($info);

        $store = [];
        $published = [];
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturnCallback(static function (string $key) use (&$store) {
            return $store[$key] ?? false;
        });
        $redis->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): bool {
            $store[$key] = (string) $value;
            return true;
        });
        $redis->method('publish')->willReturnCallback(static function (string $ch, string $json) use (&$published): int {
            $published[] = json_decode($json, true);
            return 1;
        });
        $redis->method('lPush')->willReturn(1);
        $redis->method('lTrim')->willReturn(true);

        $emitter = new PregaoEmitService($pdo, $redis);
        $qid = '999001';
        $payload = [
            'robot' => 'PERGUNTAS',
            'level' => 'alert',
            'icon' => '❓',
            'msg' => 'PERGUNTA ABERTA há 3h00 — MLB1 "teste"',
        ];
        $fp = ['question_id' => $qid, 'alert' => 'open_gt_2h'];

        $a = $emitter->emitOpOnTransition('PERGUNTA_ABERTA:' . $qid, $payload, $fp, 1335, 'live');
        $b = $emitter->emitOpOnTransition('PERGUNTA_ABERTA:' . $qid, $payload, $fp, 1335, 'live');
        $c = $emitter->emitOpOnTransition('PERGUNTA_ABERTA:' . $qid, $payload, $fp, 1335, 'live');

        $this->assertNotNull($a);
        $this->assertNull($b);
        $this->assertNull($c);
        $ops = array_values(array_filter($published, static fn (array $e): bool => ($e['type'] ?? '') === 'op'));
        $this->assertCount(1, $ops);
    }

    public function testTaxaSobreRecebidasNaoSobreRespondidas(): void
    {
        // 8 respondidas de 10 recebidas = 80% (não 100%)
        $recebidas = 10;
        $respondidas = 8;
        $taxa = round(100.0 * $respondidas / $recebidas, 1);
        $this->assertSame(80.0, $taxa);
        $this->assertSame(
            'amarelo',
            PregaoQuestionsService::resolveCardStatus($taxa, [])
        );
    }
}
