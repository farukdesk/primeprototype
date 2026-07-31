package bd.ac.primeuniversity.studentportal.ui.notifications

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.data.model.AppNotification
import bd.ac.primeuniversity.studentportal.databinding.ItemAppNotificationBinding

/** List adapter for the announcements inbox. */
class AppNotificationAdapter(
    private val onClick: (AppNotification) -> Unit,
) : ListAdapter<AppNotification, AppNotificationAdapter.VH>(DIFF) {

    inner class VH(val binding: ItemAppNotificationBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemAppNotificationBinding.inflate(
            LayoutInflater.from(parent.context), parent, false
        )
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        val item = getItem(position)
        with(holder.binding) {
            date.text = item.date
            title.text = item.title
            body.text = item.body
            root.setOnClickListener { onClick(item) }
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<AppNotification>() {
            override fun areItemsTheSame(a: AppNotification, b: AppNotification) = a.id == b.id
            override fun areContentsTheSame(a: AppNotification, b: AppNotification) = a == b
        }
    }
}
