import React from 'react';
import {Composition} from 'remotion';
import {Promo, promoLength} from './Promo';

export const Root: React.FC = () => (
	<Composition id="Promo" component={Promo} durationInFrames={promoLength()} fps={30} width={1080} height={1080} />
);
