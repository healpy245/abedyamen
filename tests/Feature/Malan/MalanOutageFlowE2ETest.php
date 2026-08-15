<?php

declare(strict_types=1);

namespace Tests\Feature\Malan;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotToolExecution;
use App\Models\Malan\MalanSupportReport;
use App\Models\User;
use App\Services\AiChatbot\AiChatbotService;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MalanOutageFlowE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkspaceUserSeeder::class);

        config([
            'services.openai.api_key' => 'test-openai',
            'malan.api.base_url' => 'https://www.malan.app',
            'malan.api.key' => 'test-malan-key',
            'malan.api.retries' => 0,
        ]);
    }

    private function user(): User
    {
        return User::where('email', 'yamen@kaman.rest')->firstOrFail();
    }

    private function malanInstance(): ChatbotInstance
    {
        return ChatbotInstance::factory()->create([
            'user_id' => $this->user()->id,
            'integration_type' => 'malan',
            'system_prompt' => 'سالي لملان. اتبعي تدفق الانقطاع واستخدمي الأدوات.',
        ]);
    }

    public function test_scenario_a_active_creates_support_report_after_tool_success(): void
    {
        $openAiCalls = 0;

        Http::fake(function (Request $request) use (&$openAiCalls) {
            if (str_contains($request->url(), 'malan.app')) {
                return Http::response([[
                    'result' => true,
                    'data' => [
                        'client' => [
                            'id' => '3119',
                            'client_name' => 'מאמון טהה',
                            'client_phone' => '0536079841',
                            'status' => 'ACTIVE',
                        ],
                        'financial_summary' => ['balance' => 0],
                    ],
                ]], 200);
            }

            if (str_contains($request->url(), 'api.openai.com')) {
                $openAiCalls++;

                if ($openAiCalls === 1) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_lookup',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'lookup_malan_customer',
                                        'arguments' => json_encode([
                                            'lookup_type' => 'phone',
                                            'value' => '0536079841',
                                            'reason' => 'internet_outage',
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                if ($openAiCalls === 2) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_report',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'create_malan_support_report',
                                        'arguments' => json_encode([
                                            'issue_type' => 'full_outage',
                                            'summary' => 'انقطاع كامل',
                                            'confirmed_by_customer' => true,
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                $payload = $request->data();
                $messages = $payload['messages'] ?? [];
                $hasReportTool = collect($messages)->contains(function ($m) {
                    return ($m['role'] ?? null) === 'tool'
                        && str_contains((string) ($m['content'] ?? ''), '"success":true');
                });
                $this->assertTrue($hasReportTool);

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => 'فحصت الحساب، الخط ظاهر فعال ومش مفصول بسبب دين. رفعت بلاغ لمشكلتك، إن شاء الله بالوقت القريب بنتواصل معك وبنحل الموضوع.',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $instance = $this->malanInstance();
        $result = app(AiChatbotService::class)->sendMessage(
            $this->user(),
            $instance,
            'النت مقطوع بالكامل ورقمي 0536079841',
            null,
            ['channel' => 'web'],
        );

        $this->assertStringContainsString('بلاغ', $result['assistant_message']->message);
        $this->assertSame(1, MalanSupportReport::query()->count());
        $this->assertDatabaseHas('malan_support_reports', [
            'external_customer_id' => '3119',
            'status' => 'OPEN',
            'source_channel' => 'web',
        ]);
        $this->assertGreaterThanOrEqual(1, ChatbotToolExecution::query()->where('tool_name', 'lookup_malan_customer')->count());
    }

    public function test_scenario_b_debt_disconnected_offers_payment_options(): void
    {
        $openAiCalls = 0;

        Http::fake(function (Request $request) use (&$openAiCalls) {
            if (str_contains($request->url(), 'malan.app')) {
                return Http::response([[
                    'result' => true,
                    'data' => [
                        'client' => [
                            'id' => '3119',
                            'client_name' => 'מאמון טהה',
                            'client_phone' => '0536079841',
                            'status' => 'DEBT_DISCONNECTED',
                        ],
                        'financial_summary' => ['balance' => -318],
                    ],
                ]], 200);
            }

            if (str_contains($request->url(), 'api.openai.com')) {
                $openAiCalls++;
                if ($openAiCalls === 1) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'tool_calls' => [[
                                    'id' => 'call_lookup',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'lookup_malan_customer',
                                        'arguments' => json_encode([
                                            'lookup_type' => 'phone',
                                            'value' => '0536079841',
                                            'reason' => 'internet_outage',
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => 'فحصت الحساب، ظاهر إنه الخدمة انفصلت أوتوماتيكي بسبب دفعة شهرية ما مرقت. الدين الحالي الظاهر بالنظام هو ₪318. كيف بتحب تسدده: تحويل بنكي ولا بطاقة فيزا؟',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $instance = $this->malanInstance();
        $result = app(AiChatbotService::class)->sendMessage(
            $this->user(),
            $instance,
            '0536079841',
            null,
            ['channel' => 'whatsapp'],
        );

        $this->assertStringContainsString('318', $result['assistant_message']->message);
        $this->assertStringContainsString('فيزا', $result['assistant_message']->message);
        $this->assertDatabaseHas('ai_chatbot_conversation_contexts', [
            'verified_customer_id' => '3119',
            'customer_status' => 'DEBT_DISCONNECTED',
            'debt_amount' => 318.00,
        ]);
    }

    public function test_scenario_c_visa_saved_card_stays_integration_pending(): void
    {
        $openAiCalls = 0;

        Http::fake(function (Request $request) use (&$openAiCalls) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $openAiCalls++;
                if ($openAiCalls === 1) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'tool_calls' => [[
                                    'id' => 'call_charge',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'charge_malan_saved_payment_method',
                                        'arguments' => json_encode(['confirmed_by_customer' => true]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => 'الدفع الإلكتروني ما زال يحتاج متابعة من الجباية، رح أحولهم يتواصلوا معك. ما بقدر أأكد إن الدفع نجح هلق.',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $user = $this->user();
        $instance = $this->malanInstance();
        $conversation = ChatbotConversation::create([
            'user_id' => $user->id,
            'instance_id' => $instance->id,
        ]);

        // Seed verified debt context.
        app(\App\Services\Malan\MalanConversationContextService::class)->storeLookupResult(
            $conversation,
            $instance,
            new \App\Data\Malan\MalanCustomerLookupResult(
                success: true,
                found: true,
                customer: [
                    'id' => '3119',
                    'name' => 'Test',
                    'phone_masked' => '053***9841',
                    'identity_masked' => null,
                    'status' => 'DEBT_DISCONNECTED',
                    'city' => null,
                ],
                financial: ['balance_raw' => -318.0, 'debt_amount' => 318.0, 'currency' => 'ILS'],
            ),
        );

        $result = app(AiChatbotService::class)->sendMessage(
            $user,
            $instance,
            'جرب البطاقة المسجلة',
            $conversation->id,
            ['channel' => 'whatsapp'],
        );

        $this->assertStringNotContainsString('تم الدفع بنجاح', $result['assistant_message']->message);
        $this->assertStringNotContainsString('نجحت عملية الدفع', $result['assistant_message']->message);
        $this->assertDatabaseHas('ai_chatbot_tool_executions', [
            'tool_name' => 'charge_malan_saved_payment_method',
            'success' => false,
        ]);
    }

    public function test_tool_loop_has_maximum_iterations(): void
    {
        $openAiCalls = 0;

        Http::fake(function (Request $request) use (&$openAiCalls) {
            if (str_contains($request->url(), 'malan.app')) {
                return Http::response([[
                    'result' => true,
                    'data' => [
                        'client' => ['id' => '1', 'status' => 'ACTIVE', 'client_phone' => '0536079841'],
                        'financial_summary' => [],
                    ],
                ]], 200);
            }

            if (str_contains($request->url(), 'api.openai.com')) {
                $openAiCalls++;

                // Keep requesting tools until the service forces a final non-tool reply.
                if ($openAiCalls <= 3) {
                    return Http::response([
                        'choices' => [[
                            'message' => [
                                'tool_calls' => [[
                                    'id' => 'call_'.$openAiCalls,
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'lookup_malan_customer',
                                        'arguments' => json_encode([
                                            'lookup_type' => 'phone',
                                            'value' => '0536079841',
                                            'reason' => 'internet_outage',
                                        ]),
                                    ],
                                ]],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'choices' => [[
                        'message' => [
                            'content' => 'رح أحول الموضوع لموظف يتابع معك.',
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $result = app(AiChatbotService::class)->sendMessage(
            $this->user(),
            $this->malanInstance(),
            '0536079841',
            null,
            ['channel' => 'web'],
        );

        $this->assertNotSame('', $result['assistant_message']->message);
        $this->assertLessThanOrEqual(4, $openAiCalls);
    }
}
