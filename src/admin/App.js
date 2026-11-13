import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { HtmlNotice, JobNotice, PageHeader, TabNav } from '../components';
import { get, post, errorMessage } from './api';
import { useConfirm } from './confirm';
import { AppContext } from './context';
import { navigate, urlFor, useQuery } from './router';
import AddScreen from './AddScreen';
import CardScreen from './CardScreen';
import CombineTab from './CombineTab';
import HistoryTab from './HistoryTab';
import MakeSimpleScreen from './MakeSimpleScreen';
import SplitTab from './SplitTab';

const TABS = [ 'combine', 'split', 'history' ];

/**
 * The page: its title, the tabs, the notice for the last change and the bulk job's progress, then the screen the
 * URL names.
 */
export default function App( { boot } ) {
	const query = useQuery();
	const tab = TABS.includes( query.tab ) ? query.tab : 'combine';
	const [ status, setStatus ] = useState(
		boot.status || { counts: {}, scanned: '', job: null }
	);
	const [ notice, setNotice ] = useState( null );
	const [ version, setVersion ] = useState( 0 );
	const [ stopping, setStopping ] = useState( false );
	const [ scanning, setScanning ] = useState( false );
	const [ ask, dialog ] = useConfirm();
	const job = status.job;
	const jobRunning = !! ( job && job.running );

	const refreshStatus = useCallback( () => {
		get( '/status' ).then(
			( next ) =>
				// The job is followed on its own; a finished one is reported once, by whoever asked.
				setStatus( ( s ) => ( { ...next, job: s.job || next.job } ) ),
			() => {}
		);
	}, [] );
	const changed = useCallback( () => {
		setVersion( ( n ) => n + 1 );
		refreshStatus();
	}, [ refreshStatus ] );
	const followJob = useCallback(
		( next ) => setStatus( ( s ) => ( { ...s, job: next } ) ),
		[]
	);
	const setCombineCount = useCallback(
		( count, scanned ) =>
			setStatus( ( s ) =>
				s.counts.combine === count && s.scanned === scanned
					? s
					: { ...s, scanned, counts: { ...s.counts, combine: count } }
			),
		[]
	);

	// The bulk job runs on the server and finishes without this page. While the page is open it asks for the next
	// batch as soon as one is done, which also keeps the progress current.
	useEffect( () => {
		if ( ! job ) {
			return undefined;
		}
		if ( ! job.running ) {
			setNotice( { status: job.status, html: job.html } );
			setStatus( ( s ) => ( { ...s, job: null } ) );
			setStopping( false );
			changed();
			return undefined;
		}
		const timer = window.setTimeout(
			() => {
				post( '/job' ).then(
					( answer ) => followJob( answer.job ),
					// Try again shortly; the job itself carries on in the background.
					() =>
						followJob( {
							...job,
							worked: false,
							retry: Date.now(),
						} )
				);
			},
			job.worked ? 100 : 2000
		);
		return () => window.clearTimeout( timer );
	}, [ job, changed, followJob ] );

	const stopJob = () => {
		setStopping( true );
		post( '/job/stop' ).then(
			( answer ) => followJob( answer.job ),
			( error ) => {
				setStopping( false );
				setNotice( {
					status: 'error',
					html: '',
					text: errorMessage( error ),
				} );
			}
		);
	};

	const scanAgain = () => {
		setScanning( true );
		post( '/scan' ).then(
			() => {
				setScanning( false );
				changed();
			},
			() => setScanning( false )
		);
	};

	const undoCreated = async ( id ) => {
		const yes = await ask(
			__(
				'Delete this variable product and show its simple products again?',
				'variation-converter-for-woocommerce'
			),
			__( 'Undo conversion', 'variation-converter-for-woocommerce' )
		);
		if ( ! yes ) {
			return;
		}
		try {
			const answer = await post( '/combine/undo', { product: id } );
			setNotice( answer.notice );
		} catch ( error ) {
			setNotice( { status: 'error', text: errorMessage( error ) } );
		}
		changed();
	};

	const context = useMemo(
		() => ( {
			status,
			version,
			jobRunning,
			notify: setNotice,
			changed,
			followJob,
			setCombineCount,
		} ),
		[ status, version, jobRunning, changed, followJob, setCombineCount ]
	);

	const labels = {
		combine: __( 'Combine', 'variation-converter-for-woocommerce' ),
		split: __( 'Split', 'variation-converter-for-woocommerce' ),
		history: __( 'History', 'variation-converter-for-woocommerce' ),
	};
	const tabQuery = ( key ) => ( 'combine' === key ? {} : { tab: key } );

	let screen;
	if ( 'history' === tab ) {
		screen = <HistoryTab query={ query } />;
	} else if ( 'split' === tab ) {
		screen = query.make_simple ? (
			<MakeSimpleScreen query={ query } />
		) : (
			<SplitTab query={ query } />
		);
	} else if ( query.add ) {
		screen = <AddScreen query={ query } />;
	} else if ( query.combine || query.grid ) {
		screen = <CardScreen query={ query } />;
	} else {
		screen = <CombineTab query={ query } />;
	}

	return (
		<AppContext.Provider value={ context }>
			<PageHeader
				title={ __(
					'Variation Converter',
					'variation-converter-for-woocommerce'
				) }
				version={ boot.version }
				action={
					'combine' === tab && (
						<Button
							variant="secondary"
							size="compact"
							isBusy={ scanning }
							disabled={ scanning }
							onClick={ scanAgain }
						>
							{ __(
								'Scan again',
								'variation-converter-for-woocommerce'
							) }
						</Button>
					)
				}
				note={ 'combine' === tab ? status.scanned : '' }
			/>
			<TabNav
				current={ tab }
				tabs={ TABS.map( ( key ) => ( {
					key,
					label: labels[ key ],
					count: status.counts[ key ],
					href: urlFor( tabQuery( key ) ),
				} ) ) }
				onChange={ ( key ) => {
					setNotice( null );
					navigate( tabQuery( key ) );
				} }
			/>
			<div className="varconv-notices">
				{ notice && (
					<HtmlNotice
						status={ notice.status }
						html={ notice.html }
						onRemove={ () => setNotice( null ) }
						actions={
							notice.undo
								? [
										{
											label: __(
												'Undo conversion',
												'variation-converter-for-woocommerce'
											),
											onClick: () =>
												undoCreated( notice.undo ),
											variant: 'secondary',
										},
									]
								: undefined
						}
					>
						{ notice.text }
					</HtmlNotice>
				) }
				{ jobRunning && (
					<JobNotice
						text={ job.text }
						done={ job.done }
						total={ job.total }
						stopping={ stopping || job.stopped }
						onStop={ stopJob }
					/>
				) }
			</div>
			{ screen }
			{ dialog }
		</AppContext.Provider>
	);
}
