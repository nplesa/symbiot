const parseBoolean = (value: string | undefined): boolean => {
  return value === 'true';
};

const parsePhones = (value: string | undefined): string[] => {
  if (!value) {
    return [];
  }

  try {
    const phones = JSON.parse(value);

    if (!Array.isArray(phones)) {
      return [];
    }

    return phones.map(String);
  } catch {
    console.error('Invalid VITE_PARENTS_PHONES');
    return [];
  }
};

export const config = {
  appName: import.meta.env.VITE_APP_NAME,
  appUrl: import.meta.env.VITE_APP_URL,
  apiUrl: import.meta.env.VITE_API_URL,

  enableBackground: parseBoolean(
    import.meta.env.VITE_ENABLE_BACKGROUND
  ),

  enableCamera: parseBoolean(
    import.meta.env.VITE_ENABLE_CAMERA
  ),

  enableMicrophone: parseBoolean(
    import.meta.env.VITE_ENABLE_MICROPHONE
  ),

  locationInterval: Number(
    import.meta.env.VITE_LOCATION_INTERVAL || 10000
  ),

  locationDistanceFilter: Number(
    import.meta.env.VITE_LOCATION_DISTANCE_FILTER || 10
  ),

  parentsPhones: parsePhones(
    import.meta.env.VITE_PARENTS_PHONES
  )
};
