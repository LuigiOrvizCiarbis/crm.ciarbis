<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\Langfuse\UsageDetails;
use OpenAI\Responses\Chat\CreateResponseUsage;
use PHPUnit\Framework\TestCase;

class UsageDetailsTest extends TestCase
{
    public function test_openai_usage_uses_exclusive_buckets(): void
    {
        $usage = CreateResponseUsage::from([
            'prompt_tokens' => 100,
            'completion_tokens' => 30,
            'total_tokens' => 130,
            'prompt_tokens_details' => ['cached_tokens' => 80],
            'completion_tokens_details' => ['reasoning_tokens' => 10],
        ]);

        $this->assertSame(
            ['input' => 20, 'input_cached_tokens' => 80, 'output' => 20, 'output_reasoning_tokens' => 10],
            UsageDetails::fromOpenAi($usage),
        );
    }

    public function test_anthropic_array_without_usage_is_null(): void
    {
        $this->assertNull(UsageDetails::fromAnthropicArray(null));
    }

    public function test_anthropic_array_maps_cache_buckets(): void
    {
        $this->assertSame(
            ['input' => 10, 'output' => 5, 'input_cache_read' => 200],
            UsageDetails::fromAnthropicArray([
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 200,
                'cache_creation_input_tokens' => 0,
            ]),
        );
    }
}
