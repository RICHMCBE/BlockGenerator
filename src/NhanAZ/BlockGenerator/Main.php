<?php

declare(strict_types=1);

namespace NhanAZ\BlockGenerator;

use NhanAZ\libBedrock\StringToBlock;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Fence;
use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use pocketmine\world\sound\FizzSound;
use RoMo\MineExp\MineExp;

class Main extends PluginBase implements Listener {

    /** @var array<Block> $blocks */
    private $blocks = [];

    /** @var array<string, array<Block>> $fenceSpecificBlocks */
    private $fenceSpecificBlocks = [];

    /** @var array<string> $supportedFences */
    private $supportedFences = ["OAK_FENCE", "NETHER_BRICK_FENCE", "BIRCH_FENCE"];

    /** @var array<string, array<string, float>> $fenceBlocksData */
    private $fenceBlocksData = [
        "OAK_FENCE" => [
            "STONE" => 50,
            "COAL_ORE" => 12,
            "LAPIS_ORE" => 11,
            "REDSTONE_ORE" => 10,
            "IRON_ORE" => 7,
            "GOLD_ORE" => 5,
            "DIAMOND_ORE" => 3,
            "EMERALD_ORE" => 2,
        ],
        "NETHER_BRICK_FENCE" => [
            "STONE" => 32,
            "COAL_ORE" => 10,
            "LAPIS_ORE" => 8,
            "REDSTONE_ORE" => 8,
            "IRON_ORE" => 13,
            "GOLD_ORE" => 12,
            "DIAMOND_ORE" => 8,
            "EMERALD_ORE" => 7,
            "ancient_debris" => 2
        ],
        "BIRCH_FENCE" => [
            "STONE" => 45,
            "COAL_ORE" => 14,
            "LAPIS_ORE" => 10,
            "REDSTONE_ORE" => 10,
            "IRON_ORE" => 9,
            "GOLD_ORE" => 6,
            "DIAMOND_ORE" => 3,
            "EMERALD_ORE" => 3
        ]
    ];

    /** @var bool $produceSound */
    private $produceSound = true;

    /** @var string $generatorMode */
    private $generatorMode = "interact"; // interact or nonInteract

    /** @var bool $checkSource */
    private $checkSource = false;

    /** @var int $delayTime */
    private $delayTime = 0; // 단위: 초

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->validateBlocksData();
        $this->buildBlocks();
    }

    private function validateBlocksData(): void {
        // 각 울타리 유형별 설정 검증
        foreach ($this->supportedFences as $fenceType) {
            if (!isset($this->fenceBlocksData[$fenceType])) {
                throw new \Exception("블록 데이터가 $fenceType 에 대해 정의되지 않았습니다!");
            }

            $blocks = $this->fenceBlocksData[$fenceType];
            $totalPercentage = 0;

            foreach ($blocks as $block => $percentage) {
                $totalPercentage += $percentage;
                // 블록이 유효한지 테스트
                try {
                    StringToBlock::parse($block);
                } catch (\Exception $e) {
                    throw new \Exception("\"$block\"은(는) 유효한 블록이 아닙니다: " . $e->getMessage());
                }

                if (!is_numeric($percentage)) {
                    throw new \Exception("$fenceType 의 \"$block\" 확률은 숫자여야 합니다!");
                }
                if ($percentage <= 0) {
                    throw new \Exception("$fenceType 의 \"$block\" 확률은 0보다 커야 합니다!");
                }
            }

            if (abs($totalPercentage - 100) > 0.01) { // 부동소수점 오차 허용
                throw new \Exception("$fenceType 의 총 확률이 유효하지 않습니다. 블록의 확률 합계는 100%여야 합니다!");
            }
        }
    }

    /**
     * Get the minimum value from an associative array.
     *
     * @param array<string, float> $array The input associative array.
     *
     * @return float The minimum value from the associative array.
     */
    private function getMinValueFromAssociativeArray(array $array): float {
        $min = PHP_INT_MAX;
        foreach ($array as $value) {
            $min = min($min, $value);
        }
        return $min;
    }

    /**
     * Convert a float to the hacking format.
     * Example: 0.9 return 10, 0.98 return 100, 0.987 return 1000,...
     * If !($number > 0 and $number < 1) return 1.
     * */
    private function floatToHachkingFormat(float $number): float {
        if ($number > 0 and $number < 1) {
            /** Convert to string, get the decimal part and calculate its length */
            $number = strval($number);
            $number = explode(".", $number);
            $number = $number[1];
            $number = strlen($number);
            /** Repeat zeroes as many times as the decimal part length */
            $number = str_repeat("0", $number);
            /** Concatenate the zeroes to 1 and convert back to int */
            $number = "1" . $number;
            $number = intval($number);
            return $number;
        }
        /** Return 1 if float is already in hachking format */
        return 1;
    }

    private function buildBlocks(): void {
        // 각 울타리별 블록 배열 구성
        foreach ($this->supportedFences as $fenceType) {
            $this->fenceSpecificBlocks[$fenceType] = [];
            $fenceBlocks = $this->fenceBlocksData[$fenceType];

            if (is_array($fenceBlocks)) {
                $min = $this->getMinValueFromAssociativeArray($fenceBlocks);
                $min = $this->floatToHachkingFormat($min);

                foreach ($fenceBlocks as $block => $percentage) {
                    $numberOfElements = round($percentage * $min);
                    for ($i = 0; $i < $numberOfElements; $i++) {
                        array_push($this->fenceSpecificBlocks[$fenceType], $block);
                    }
                }
                shuffle($this->fenceSpecificBlocks[$fenceType]);
            }
        }
    }

    private function setBlock(Position $blockPos, string $fenceType): void {
        if (!isset($this->fenceSpecificBlocks[$fenceType]) || empty($this->fenceSpecificBlocks[$fenceType])) {
            return; // 블록 데이터가 없거나 비어있으면 처리하지 않음
        }

        $blockArray = $this->fenceSpecificBlocks[$fenceType];
        $block = strval($blockArray[array_rand($blockArray)]);
        $block = StringToBlock::parse($block);
        $blockPos->getWorld()->setBlock($blockPos, $block, false);

        // MineExp 플러그인에 울타리로 생성된 블록 위치 등록
        $mineExpPlugin = $this->getServer()->getPluginManager()->getPlugin("MineExp");
        if ($mineExpPlugin !== null && $mineExpPlugin instanceof MineExp) {
            $mineExpPlugin->addFenceGeneratedBlock($blockPos);
        }
    }

    private function playFizzSound(Position $blockPos): void {
        if ($this->produceSound) {
            $blockPos->getWorld()->addSound($blockPos->add(0.5, 0.5, 0.5), new FizzSound());
        }
    }

    /**
     * 울타리 유형을 식별하는 함수
     */
    private function getFenceType(Block $block): ?string {
        if (!($block instanceof Fence)) {
            return null;
        }

        // 블록의 이름 또는 ID를 가져와서 지원하는 울타리 유형과 비교
        $blockName = $block->getName();

        if (strpos($blockName, "Oak Fence") !== false) {
            return "OAK_FENCE";
        } elseif (strpos($blockName, "Nether Brick Fence") !== false) {
            return "NETHER_BRICK_FENCE";
        } elseif (strpos($blockName, "Birch Fence") !== false) {
            return "BIRCH_FENCE";
        }

        return null;
    }

    public function onBlockUpdate(BlockUpdateEvent $event): void {
        $block = $event->getBlock();
        if ($block instanceof Fence) {
            // 지원하는 울타리 유형인지 확인
            $fenceType = $this->getFenceType($block);
            if ($fenceType === null) {
                return; // 지원하지 않는 울타리 유형은 처리하지 않음
            }

            if ($this->generatorMode == "nonInteract") {
                foreach ($block->getAllSides() as $facing => $block) {
                    $side = $block->getSide($facing);
                    if ($side instanceof Water or $block instanceof Water) {
                        if ($this->checkSource) {
                            if ($block instanceof Liquid) {
                                if ($block->isSource()) {
                                    continue;
                                }
                            }
                        }
                        if ($block->getTypeId() === BlockTypeIds::AIR or $block->getTypeId() === BlockTypeIds::WATER) {
                            if ($this->delayTime > 0) {
                                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($block, $fenceType): void {
                                    $this->setBlock($block->getPosition(), $fenceType);
                                    $this->playFizzSound($block->getPosition());
                                }), intval($this->delayTime) * 20);
                            } elseif ($this->delayTime == 0) {
                                $this->setBlock($block->getPosition(), $fenceType);
                                $this->playFizzSound($block->getPosition());
                            }
                        }
                    }
                }
                return;
            }

            if ($this->generatorMode == "interact") {
                foreach ($block->getAllSides() as $block) {
                    if ($block instanceof Water) {
                        if ($this->checkSource) {
                            if ($block instanceof Liquid) {
                                if ($block->isSource()) {
                                    continue;
                                }
                            }
                        }
                        if ($block->getTypeId() === BlockTypeIds::AIR or $block->getTypeId() === BlockTypeIds::WATER) {
                            if ($this->delayTime > 0) {
                                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($block, $fenceType): void {
                                    $this->setBlock($block->getPosition(), $fenceType);
                                    $this->playFizzSound($block->getPosition());
                                }), intval($this->delayTime) * 20);
                            } elseif ($this->delayTime == 0) {
                                $this->setBlock($block->getPosition(), $fenceType);
                                $this->playFizzSound($block->getPosition());
                            }
                        }
                    }
                }
                return;
            }
        }
    }
}
