const PADDLE_SRC = 'https://cdn.paddle.com/paddle/v2/paddle.js';

interface PaddleEvent {
  name?: string;
}

interface PaddleGlobal {
  Environment: { set(environment: string): void };
  Initialize(options: { token: string; eventCallback?: (event: PaddleEvent) => void }): void;
  Update(options: { eventCallback?: (event: PaddleEvent) => void }): void;
  Checkout: { open(options: { transactionId: string }): void };
  Initialized?: boolean;
}

declare global {
  interface Window {
    Paddle?: PaddleGlobal;
  }
}

let loading: Promise<PaddleGlobal> | null = null;

function load(): Promise<PaddleGlobal> {
  if (window.Paddle !== undefined) {
    return Promise.resolve(window.Paddle);
  }

  loading ??= new Promise<PaddleGlobal>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = PADDLE_SRC;
    script.async = true;
    script.onload = () => (window.Paddle === undefined ? reject(new Error('paddle_unavailable')) : resolve(window.Paddle));
    script.onerror = () => {
      loading = null;
      reject(new Error('paddle_unavailable'));
    };
    document.head.appendChild(script);
  });

  return loading;
}

export async function openPaddleCheckout(
  checkout: { environment: 'sandbox' | 'production'; client_token: string | null; transaction_id: string },
  onEvent: (name: string) => void,
): Promise<void> {
  if (checkout.client_token === null) {
    throw new Error('paddle_unavailable');
  }

  const paddle = await load();
  const eventCallback = (event: PaddleEvent): void => {
    if (typeof event.name === 'string') {
      onEvent(event.name);
    }
  };

  if (paddle.Initialized) {
    paddle.Update({ eventCallback });
  } else {
    if (checkout.environment === 'sandbox') {
      paddle.Environment.set('sandbox');
    }

    paddle.Initialize({ token: checkout.client_token, eventCallback });
  }

  paddle.Checkout.open({ transactionId: checkout.transaction_id });
}
