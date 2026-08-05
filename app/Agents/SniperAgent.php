<?php
declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\SEO\CompetitorSpy;
use App\Services\ItemService;

class SniperAgent extends BaseAgent
{
    private bool $dryRun = false;

    public function __construct()
    {
        parent::__construct('sniper');
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function run(): void
    {
        $this->scanForOpportunities();
        $this->updateLastRun();
    }

    private function scanForOpportunities(): void
    {
        // 1. Find items configured for Auto-Repricing, asking for account_id
        $sql = "
            SELECT account_id, ml_item_id, title, price, min_price, max_price 
            FROM items 
            WHERE auto_reprice = 1 
            AND status = 'active'
            ORDER BY account_id
            LIMIT 50
        ";
        
        $items = $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($items)) {
            $this->log('info', "Nenhum item configurado para Reprificação Automática (auto_reprice=1).");
            return;
        }

        // Group items by account to instantiate services correctly
        $itemsByAccount = [];
        foreach ($items as $item) {
            $itemsByAccount[$item['account_id']][] = $item;
        }

        foreach ($itemsByAccount as $accountId => $accountItems) {
            $this->processAccountItems((int)$accountId, $accountItems);
        }
    }

    private function processAccountItems(int $accountId, array $items): void
    {
        try {
            // Instantiate services for this account
            $spy = new CompetitorSpy($accountId);
            $itemService = new ItemService($accountId);

            foreach ($items as $item) {
                // Use CompetitorSpy to analyze the market for this item
                // using the title as the search term
                $marketData = $spy->spyProduct($item['title'], 15);
                
                if (isset($marketData['error']) || empty($marketData['price_analysis'])) {
                    continue; 
                }

                $analysis = $marketData['price_analysis'];
                $marketMin = (float)$analysis['min'];
                
                // If market min is 0 or invalid, skip
                if ($marketMin <= 0) continue;

                $myPrice   = (float)$item['price'];
                $minAllowed = (float)$item['min_price'];
                $maxAllowed = (float)($item['max_price'] ?? 0);

                if ($minAllowed <= 0) {
                     $this->log('warning', "Item {$item['ml_item_id']} ignorado: min_price não definido.", ['item_id' => $item['ml_item_id']]);
                     continue;
                }

                // Strategy: Undercut cheapest competitor by R$ 0.10
                $targetPrice = $marketMin - 0.10; 

                // Ensure we don't go below our floor
                if ($targetPrice < $minAllowed) {
                    $targetPrice = $minAllowed;
                }

                // Ensure we don't go above ceiling (if defined)
                if ($maxAllowed > 0 && $targetPrice > $maxAllowed) {
                    $targetPrice = $maxAllowed;
                }
                
                // Check if update is needed (diff > 0.05)
                if (abs($myPrice - $targetPrice) > 0.05) {
                    
                    if ($targetPrice < $myPrice) {
                        // Lowering price to compete
                        if ($this->dryRun) {
                            $this->log('action', "[DRY-RUN] Sniper Shot: Baixaria de R$ $myPrice para R$ $targetPrice (Min Mercado: R$ $marketMin)", [
                                'item_id' => $item['ml_item_id'],
                                'old_price' => $myPrice,
                                'new_price' => $targetPrice,
                                'market_min' => $marketMin
                            ]);
                        } else {
                            $this->log('action', "Sniper Shot: Baixando de R$ $myPrice para R$ $targetPrice (Min Mercado: R$ $marketMin)", [
                                'item_id' => $item['ml_item_id'],
                                'old_price' => $myPrice,
                                'new_price' => $targetPrice,
                                'market_min' => $marketMin
                            ]);
                            $itemService->updatePrice($item['ml_item_id'], $targetPrice);
                        }
                        
                    } elseif ($targetPrice > $myPrice) {
                        // Raising price (Profit Maximization) if market allows
                        // i.e., I was way cheaper than min market (excluding me? spyProduct includes me potentially)
                        // If spyProduct returned ME as min, then marketMin == myPrice. targetPrice = myPrice - 0.10.
                        // Then targetPrice < myPrice. We lower it further? No.
                        
                        // We need to be careful. spyProduct returns min of TOP results.
                        // If I am the min, I shouldn't lower further against myself.
                        // But finding the "Second Lowest" is hard with just aggregate data.
                        
                        // Heuristic: only raise if marketMin is significantly higher than me
                        // But if marketMin == myPrice, we hold.
                        
                        // If targetPrice (marketMin - 0.10) > myPrice, it implies marketMin > myPrice + 0.10.
                        // This means I am NOT the min. Someone else is the min (higher than me).
                        // So I can raise my price to match them - 0.10.
                        
                        if ($this->dryRun) {
                            $this->log('action', "[DRY-RUN] Sniper Profit: Subiria de R$ $myPrice para R$ $targetPrice (Acompanhando Mercado)", [
                                'item_id' => $item['ml_item_id'],
                                'old_price' => $myPrice,
                                'new_price' => $targetPrice,
                                'market_min' => $marketMin
                            ]);
                        } else {
                            $this->log('action', "Sniper Profit: Subindo de R$ $myPrice para R$ $targetPrice (Acompanhando Mercado)", [
                                'item_id' => $item['ml_item_id'],
                                'old_price' => $myPrice,
                                'new_price' => $targetPrice,
                                'market_min' => $marketMin
                            ]);
                            $itemService->updatePrice($item['ml_item_id'], $targetPrice);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('error', "Erro ao processar conta $accountId: " . $e->getMessage());
        }
    }
}
