export type LoginCredentials = {
  email: string;
  password: string;
};

const genericCredentialsError = 'Невалиден имейл или парола.';

function readCookie(name: string): string | undefined {
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split('; ')
    .find((item) => item.startsWith(prefix));

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : undefined;
}

export async function login(credentials: LoginCredentials): Promise<void> {
  await fetch('/backend/sanctum/csrf-cookie', {
    credentials: 'include',
  });

  const csrfToken = readCookie('XSRF-TOKEN');
  const response = await fetch('/backend/api/v1/auth/login', {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
    },
    body: JSON.stringify(credentials),
  });

  if (!response.ok) {
    throw new Error(
      response.status === 422
        ? genericCredentialsError
        : 'Входът временно не е достъпен. Моля, опитайте отново.',
    );
  }
}
