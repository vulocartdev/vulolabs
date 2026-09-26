<?php
/**
 * AIResponse class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * The response returned by the AI API, including the credits
 * consumed by the request. Immutable - sanitizing the content
 * (AISafetyValidator::sanitize_response()) produces a new instance via
 * with_content() rather than mutating this one.
 *
 * @class       AIResponse class
 * @version     1.0.0
 * @author      VuloLabs
 */
final class AIResponse {

    /**
     * @var string
     */
    private string $content;

    /**
     * Real AI credits this call spent, exactly as the server reported it -
     * fractional and rounded to 3 decimals for display. Never computed
     * locally.
     *
     * @var float
     */
    private float $credits_used;

    /**
     * The server's own request id for this call, when the gateway that
     * produced this response returns one - null when it doesn't (never
     * fabricated).
     *
     * @var string|null
     */
    private ?string $request_id;

    /**
     * @param string      $content      Generated content.
     * @param float       $credits_used Real AI credits this call spent, as reported by the AI service.
     * @param string|null $request_id   The request id for this call, if the gateway returned one.
     */
    public function __construct(
        string $content,
        float $credits_used,
        ?string $request_id = null
    ) {
        $this->content      = $content;
        $this->credits_used = $credits_used;
        $this->request_id   = $request_id;
    }

    /**
     * @return string
     */
    public function get_content(): string {
        return $this->content;
    }

    /**
     * @return float
     */
    public function get_credits_used(): float {
        return $this->credits_used;
    }

    /**
     * @return string|null
     */
    public function get_request_id(): ?string {
        return $this->request_id;
    }

    /**
     * Returns a copy of this response with different content - used to
     * apply sanitization without mutating the original.
     *
     * @param string $new_content Replacement content.
     * @return self
     */
    public function with_content( string $new_content ): self {
        return new self(
            $new_content,
            $this->credits_used,
            $this->request_id
        );
    }
}
