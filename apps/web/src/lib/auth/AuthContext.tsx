"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import * as authClient from "./client";
import type { User } from "./client";

type AuthStatus = "loading" | "authenticated" | "guest";

type AuthContextValue = {
  user: User | null;
  status: AuthStatus;
  refresh: () => Promise<void>;
  login: (input: { email: string; password: string }) => Promise<User>;
  register: (input: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => Promise<User>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");
  const fetchedOnce = useRef(false);

  const refresh = useCallback(async () => {
    try {
      const { data } = await authClient.getCurrentUser();
      setUser(data);
      setStatus("authenticated");
    } catch {
      // Any failure (401 unauthenticated, network error) — treat as guest.
      // Auth state is derived from the server session, not a stored flag.
      setUser(null);
      setStatus("guest");
    }
  }, []);

  useEffect(() => {
    if (fetchedOnce.current) return;
    fetchedOnce.current = true;
    void refresh();
  }, [refresh]);

  const login = useCallback(async (input: { email: string; password: string }) => {
    const { data } = await authClient.login(input);
    setUser(data);
    setStatus("authenticated");
    return data;
  }, []);

  const register = useCallback(
    async (input: {
      name: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) => {
      const { data } = await authClient.register(input);
      setUser(data);
      setStatus("authenticated");
      return data;
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await authClient.logout();
    } finally {
      setUser(null);
      setStatus("guest");
    }
  }, []);

  return (
    <AuthContext.Provider value={{ user, status, refresh, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within <AuthProvider>");
  return ctx;
}
