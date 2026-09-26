import axios from 'axios';

/**
 * VuloCloud refuses an AI request the site owner's credits can't cover -
 * before the AI provider is ever called, so nothing is charged. Every AI
 * endpoint in this plugin reports that the same way (HTTP 402,
 * `code: 'vulopilot_insufficient_credits'`, see
 * VuloPilotException::to_insufficient_credits_error()), and every AI call
 * goes through axios (directly, or via zyra's getApiResponse/
 * sendApiResponse, which use this same axios) - so one response
 * interceptor here recognizes it everywhere and raises one shared
 * "not enough credits → Buy Credits" notice (InsufficientCreditsNotice),
 * instead of each of the ~10 AI surfaces re-implementing that UI.
 */
export const INSUFFICIENT_CREDITS_EVENT = 'vulopilot:insufficient-credits';

export interface InsufficientCreditsDetail {
	creditsRemaining: number;
	buyCreditsUrl: string;
}

interface InsufficientCreditsErrorBody {
	code?: string;
	data?: { credits_remaining?: number; buy_credits_url?: string };
}

export const isInsufficientCreditsBody = (body: unknown): body is InsufficientCreditsErrorBody =>
	typeof body === 'object' &&
	body !== null &&
	(body as InsufficientCreditsErrorBody).code === 'vulopilot_insufficient_credits';

export const announceInsufficientCredits = (body: InsufficientCreditsErrorBody): void => {
	window.dispatchEvent(
		new CustomEvent<InsufficientCreditsDetail>(INSUFFICIENT_CREDITS_EVENT, {
			detail: {
				creditsRemaining: Number(body.data?.credits_remaining ?? 0),
				buyCreditsUrl: String(body.data?.buy_credits_url ?? ''),
			},
		})
	);
};

let installed = false;

/** Idempotent - safe to call from every entry point that renders AI features. */
export const installInsufficientCreditsInterceptor = (): void => {
	if (installed) {
		return;
	}
	installed = true;

	axios.interceptors.response.use(undefined, (error) => {
		const body = error?.response?.data;
		if (error?.response?.status === 402 && isInsufficientCreditsBody(body)) {
			announceInsufficientCredits(body);
		}
		return Promise.reject(error);
	});
};
