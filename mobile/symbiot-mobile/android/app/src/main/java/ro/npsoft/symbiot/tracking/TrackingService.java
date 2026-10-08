package ro.npsoft.symbiot.tracking;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.IBinder;

import androidx.annotation.Nullable;
import androidx.core.app.NotificationCompat;

import ro.npsoft.symbiot.R;

public class TrackingService extends Service {

    public static final String CHANNEL_ID = "symbiot_tracking";
    public static final int NOTIFICATION_ID = 1001;

    @Override
    public void onCreate() {
        super.onCreate();

        createNotificationChannel();

        Notification notification = createNotification();

        startForeground(
            NOTIFICATION_ID,
            notification
        );
    }

    @Override
    public int onStartCommand(
        Intent intent,
        int flags,
        int startId
    ) {

        // GPS tracking will be added here.

        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        super.onDestroy();
    }

    @Nullable
    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void createNotificationChannel() {

        NotificationManager manager =
            getSystemService(NotificationManager.class);

        NotificationChannel channel =
            new NotificationChannel(
                CHANNEL_ID,
                "Symbiot Location Tracking",
                NotificationManager.IMPORTANCE_LOW
            );

        channel.setDescription(
            "Indicates that Symbiot is tracking location."
        );

        manager.createNotificationChannel(channel);
    }

    private Notification createNotification() {

        return new NotificationCompat.Builder(
            this,
            CHANNEL_ID
        )
            .setContentTitle("Symbiot")
            .setContentText(
                "Location tracking is active"
            )
            .setSmallIcon(
                android.R.drawable.ic_menu_mylocation
            )
            .setOngoing(true)
            .build();
    }
}
