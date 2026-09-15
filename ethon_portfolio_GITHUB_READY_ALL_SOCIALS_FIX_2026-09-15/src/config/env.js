export const nodeEnv = process.env.NODE_ENV || 'development';
export const port = Number(process.env.PORT || 3000);
export const hasSupabase = Boolean(process.env.SUPABASE_URL && process.env.SUPABASE_SERVICE_ROLE_KEY);

export default Object.freeze({ nodeEnv, port, hasSupabase });
