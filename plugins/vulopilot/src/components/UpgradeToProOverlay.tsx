import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import './UpgradeToProOverlay.scss';

/** Every prop defaults to the original upgrade copy, so every
 * existing call site keeps rendering exactly what it did before - passing
 * `icon`/`title`/`desc`/`buttonText` explicitly (see `useContentGate.tsx`'s
 * own connect gate) is what makes this the same reusable
 * "locked content" card for a different real CTA, not a new component. */
interface OverlayCopy {
	icon?: string;
	title?: string;
	desc?: string;
	buttonText?: string;
}

export const UpgradeToProOverlay = ({
	onClick,
	icon = 'lock purple',
	title = __('Upgrade to Pro', 'vulopilot'),
	desc = __('Unlock the full VuloPilot toolkit', 'vulopilot'),
	buttonText = __('Upgrade to pro', 'vulopilot'),
}: { onClick?: () => void } & OverlayCopy) => (
	<div
		className="pro-section-wrapper"
		style={onClick ? { cursor: 'pointer' } : undefined}
		role={onClick ? 'button' : undefined}
		tabIndex={onClick ? 0 : undefined}
		onClick={onClick}
		onKeyDown={
			onClick
				? (event) => {
						if ('Enter' === event.key || ' ' === event.key) {
							onClick();
						}
					}
				: undefined
		}
	>
		<div
			className="pro-section"
		>
			<i className={`adminfont-${icon}`}></i>
			<div className="title">{title}</div>
			<span>{desc}</span>
			<div
				className="admin-btn btn-purple-bg"
				role={onClick ? 'button' : undefined}
				tabIndex={onClick ? 0 : undefined}
				onKeyDown={(event) => {
					if (onClick && ('Enter' === event.key || ' ' === event.key)) {
						event.preventDefault();
						onClick();
					}
				}}
			>
				{buttonText}
			</div>
		</div>
	</div>
);

export const BlurredProContent = ({
	contentClassName,
	onClick,
	children,
	...overlayCopy
}: {
	/** This dummy's own content class (e.g. `health-timeline-dummy`) - `blur-wrapper-content` is appended automatically. */
	contentClassName: string;
	onClick: () => void;
	children: ReactNode;
} & OverlayCopy) => (
	<div className="blur-wrapper">
		<UpgradeToProOverlay onClick={onClick} {...overlayCopy} />
		<div
			className={`${contentClassName} blur-wrapper-content`}
			role="button"
			tabIndex={0}
			onClick={onClick}
			onKeyDown={(event) => {
				if ('Enter' === event.key || ' ' === event.key) {
					onClick();
				}
			}}
		>
			{children}
		</div>
	</div>
);
